<?php

namespace App\Services\LinkPreview;

use App\Support\Money;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Tries to read a product page's title, price, image and shop from its Open
 * Graph tags (with <title> as a fallback). Read-only, and it never throws:
 * any problem gives a "failed" result and the user types the details (D-049).
 *
 * SSRF-safe: only http(s) on the default ports; the host must resolve to public
 * addresses only, and the request goes to the exact IP that was checked
 * (CURLOPT_RESOLVE), so a DNS trick can't swap it. Redirects are followed by
 * hand (at most 3), each checked the same way. Short timeout; 1 MB at most.
 */
class LinkPreview
{
    public const TIMEOUT_SECONDS = 5;

    public const MAX_BYTES = 1_000_000;

    public const MAX_REDIRECTS = 3;

    public function __construct(private HostResolver $resolver) {}

    public function fetch(string $url): LinkPreviewResult
    {
        $shop = ShopName::from($url);

        try {
            $response = $this->get(trim($url));

            if ($response === null || ! $response->successful()) {
                return LinkPreviewResult::failed($shop);
            }

            return $this->parse(substr($response->body(), 0, self::MAX_BYTES), $response->effectiveUri()?->__toString() ?? $url, $shop);
        } catch (Throwable) {
            return LinkPreviewResult::failed($shop);
        }
    }

    /**
     * Follows up to 3 redirects, checking every address before connecting.
     */
    private function get(string $url): ?Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->checkedTarget($url);

            $response = Http::withOptions([
                'allow_redirects' => false,
                // Connect to the IP we checked, not whatever DNS says a moment later.
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]],
                // Stop reading once the page is too big.
                'progress' => function ($downloadTotal, $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new InvalidArgumentException('Page too large.');
                    }
                },
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(3)
                ->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; SynopLinkPreview/1.0)'])
                ->get($url);

            if (! $response->redirect()) {
                return $response;
            }

            $location = $response->header('Location');

            if ($location === '') {
                return null;
            }

            $url = $this->absolute($location, $url);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port, a checked public IP
     *
     * @throws InvalidArgumentException when the address isn't allowed
     */
    private function checkedTarget(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Only plain http(s) links.');
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;

        if (isset($parts['port']) && (int) $parts['port'] !== $defaultPort) {
            throw new InvalidArgumentException('Only the default port.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw new InvalidArgumentException('Unknown host.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new InvalidArgumentException('Private address.');
            }
        }

        return [$host, $defaultPort, $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function parse(string $html, string $pageUrl, ?string $shop): LinkPreviewResult
    {
        if (trim($html) === '') {
            return LinkPreviewResult::failed($shop);
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $meta = function (array $names) use ($xpath): ?string {
            foreach ($names as $name) {
                $nodes = $xpath->query('//meta[@property="'.$name.'" or @name="'.$name.'"]/@content');
                $value = $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)?->nodeValue) : '';

                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        };

        $title = $meta(['og:title', 'twitter:title'])
            ?? trim((string) $dom->getElementsByTagName('title')->item(0)?->textContent);
        $title = $title === '' ? null : mb_substr(html_entity_decode($title, ENT_QUOTES | ENT_HTML5), 0, 255);

        $price = null;
        $amount = $meta(['og:price:amount', 'product:price:amount']);

        if ($amount !== null) {
            try {
                $price = Money::parse($amount);
                $price = $price->isNegative() ? null : $price;
            } catch (InvalidArgumentException) {
                $price = null;
            }
        }

        $image = $meta(['og:image:secure_url', 'og:image', 'twitter:image']);
        $image = $image === null ? null : $this->absolute($image, $pageUrl);
        // Only https images, so the page never loads insecure content.
        $image = $image !== null && str_starts_with($image, 'https://') && strlen($image) <= 2048 ? $image : null;

        $siteName = $meta(['og:site_name']);

        if ($title === null && $price === null && $image === null) {
            return LinkPreviewResult::failed($shop);
        }

        return new LinkPreviewResult(true, $title, $price, $image, $shop ?? ($siteName === null ? null : mb_substr($siteName, 0, 50)));
    }

    private function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        return $origin.(str_starts_with($location, '/') ? $location : '/'.$location);
    }
}
