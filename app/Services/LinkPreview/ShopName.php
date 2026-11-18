<?php

namespace App\Services\LinkPreview;

/**
 * "Shopee" from https://shopee.ph/…, or the domain itself for other shops.
 */
class ShopName
{
    private const KNOWN = [
        'shopee.' => 'Shopee',
        'lazada.' => 'Lazada',
        'tiktok.' => 'TikTok Shop',
        'zalora.' => 'Zalora',
        'amazon.' => 'Amazon',
        'shein.' => 'Shein',
        'watsons.' => 'Watsons',
    ];

    public static function from(?string $url): ?string
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        foreach (self::KNOWN as $needle => $name) {
            if (str_contains($host, $needle)) {
                return $name;
            }
        }

        return mb_substr(preg_replace('/^www\./', '', $host) ?? $host, 0, 50);
    }
}
