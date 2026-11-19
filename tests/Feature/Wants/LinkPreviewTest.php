<?php

use App\Services\LinkPreview\DescriptionParser;
use App\Services\LinkPreview\HostResolver;
use App\Services\LinkPreview\LinkPreview;
use App\Services\LinkPreview\ShopName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

beforeEach(function () {
    // Public test addresses (documentation ranges are "reserved", so use real-looking public ones).
    app()->instance(HostResolver::class, new FakeHostResolver([
        'shopee.ph' => ['143.92.88.1'],
        'shop.example.com' => ['93.184.215.14'],
        'sneaky.example.com' => ['93.184.215.15'],
        'internal.example.com' => ['192.168.1.10'],
        'mixed.example.com' => ['93.184.215.16', '10.0.0.5'],
        'localhost' => ['127.0.0.1'],
    ]));
    $this->preview = fn (string $url) => app(LinkPreview::class)->fetch($url);
    $this->page = fn (string $head, string $body = '') => "<!doctype html><html><head>{$head}</head><body>{$body}</body></html>";
});

// Reading the page

test('Open Graph tags give the title, price, image and shop', function () {
    Http::fake(['shopee.ph/*' => Http::response(($this->page)(
        '<meta property="og:title" content="COSRX Snail Mucin Essence 100ml &amp; more">
         <meta property="og:image" content="https://cf.shopee.ph/file/abc.jpg">
         <meta property="product:price:amount" content="1299.00">'
    ))]);

    $result = ($this->preview)('https://shopee.ph/cosrx-snail-i.1.2');

    expect($result->ok)->toBeTrue()
        ->and($result->title)->toBe('COSRX Snail Mucin Essence 100ml & more')
        ->and($result->price?->cents())->toBe(129900)
        ->and($result->imageUrl)->toBe('https://cf.shopee.ph/file/abc.jpg')
        ->and($result->shop)->toBe('Shopee');
});

test('without Open Graph, the page title is used', function () {
    Http::fake(['shop.example.com/*' => Http::response(($this->page)('<title>  Lip Tint – Rose  </title>'))]);

    $result = ($this->preview)('https://shop.example.com/item');

    expect($result->ok)->toBeTrue()
        ->and($result->title)->toBe('Lip Tint – Rose')
        ->and($result->price)->toBeNull()
        ->and($result->shop)->toBe('shop.example.com');
});

test('relative and insecure image links are fixed or dropped', function (string $image, ?string $expected) {
    Http::fake(['shop.example.com/*' => Http::response(($this->page)('<meta property="og:title" content="X"><meta property="og:image" content="'.$image.'">'))]);

    expect(($this->preview)('https://shop.example.com/item')->imageUrl)->toBe($expected);
})->with([
    'relative' => ['/img/x.jpg', 'https://shop.example.com/img/x.jpg'],
    'protocol-relative' => ['//cdn.example.com/x.jpg', 'https://cdn.example.com/x.jpg'],
    'http only' => ['http://cdn.example.com/x.jpg', null],
]);

test('a strange price is ignored, not a crash', function () {
    Http::fake(['shop.example.com/*' => Http::response(($this->page)('<meta property="og:title" content="X"><meta property="og:price:amount" content="call us">'))]);

    $result = ($this->preview)('https://shop.example.com/item');

    expect($result->ok)->toBeTrue()->and($result->price)->toBeNull();
});

// Failures never crash

test('a blocked, broken or empty page gives a failed result', function (Closure $response) {
    Http::fake(['shop.example.com/*' => $response]);

    $result = ($this->preview)('https://shop.example.com/item');

    expect($result->ok)->toBeFalse()->and($result->shop)->toBe('shop.example.com');
})->with([
    'blocked (403)' => fn () => Http::response('Forbidden', 403),
    'server error' => fn () => Http::response('', 500),
    'empty page' => fn () => Http::response(''),
    'no useful tags' => fn () => Http::response('<html><body>Hello</body></html>'),
    'not html' => fn () => Http::response(random_bytes(200)),
]);

test('a timeout gives a failed result', function () {
    Http::fake(fn () => throw new ConnectionException('Timed out after 5 seconds'));

    expect(($this->preview)('https://shop.example.com/item')->ok)->toBeFalse();
});

test('only the first 1 MB is read', function () {
    Http::fake(['shop.example.com/*' => Http::response(str_repeat(' ', LinkPreview::MAX_BYTES + 10).'<meta property="og:title" content="Too far">')]);

    expect(($this->preview)('https://shop.example.com/item')->ok)->toBeFalse();
});

test('the request has a short timeout and doesn\'t follow redirects on its own', function () {
    Http::fake(['shop.example.com/*' => Http::response(($this->page)('<title>X</title>'))]);

    ($this->preview)('https://shop.example.com/item');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.example.com/item');
    expect(LinkPreview::TIMEOUT_SECONDS)->toBe(5);
});

// SSRF safety

test('private, local and odd addresses are refused before any request', function (string $url) {
    Http::fake();

    expect(($this->preview)($url)->ok)->toBeFalse();
    Http::assertNothingSent();
})->with([
    'private network name' => ['http://internal.example.com/admin'],
    'one private address among several' => ['https://mixed.example.com/'],
    'localhost' => ['http://localhost/'],
    'loopback IP' => ['http://127.0.0.1/'],
    'private IP' => ['http://10.0.0.1/'],
    'link-local (cloud metadata)' => ['http://169.254.169.254/latest/meta-data'],
    'IPv6 loopback' => ['http://[::1]/'],
    'unknown host' => ['https://nowhere.example.com/'],
    'other port' => ['https://shop.example.com:8080/'],
    'not http' => ['file:///etc/passwd'],
    'ftp' => ['ftp://shop.example.com/'],
    'with a password' => ['https://user:pass@shop.example.com/'],
]);

test('special-use ranges that PHP does not flag are refused too', function (string $ip, bool $public) {
    expect(LinkPreview::isPublic($ip))->toBe($public);
})->with([
    'carrier NAT / cloud metadata' => ['100.100.100.200', false],
    'carrier NAT start' => ['100.64.0.1', false],
    'just outside carrier NAT' => ['100.128.0.1', true],
    'IETF' => ['192.0.0.8', false],
    'benchmark' => ['198.18.0.1', false],
    'test nets' => ['203.0.113.5', false],
    'multicast' => ['224.0.0.1', false],
    'IPv4-mapped loopback' => ['::ffff:127.0.0.1', false],
    'NAT64 loopback' => ['64:ff9b::7f00:1', false],
    '6to4 private' => ['2002:7f00:1::', false],
    'Teredo' => ['2001::1', false],
    'IPv6 multicast' => ['ff02::1', false],
    'public IPv4' => ['8.8.8.8', true],
    'public IPv6' => ['2606:4700::1111', true],
]);

test('a host whose name points at a special-use address is refused before any request', function () {
    Http::fake();
    app()->instance(HostResolver::class, new FakeHostResolver(['metadata.example.com' => ['100.100.100.200']]));

    expect(app(LinkPreview::class)->fetch('http://metadata.example.com/')->ok)->toBeFalse();
    Http::assertNothingSent();
});

test('a redirect to a private address is refused', function () {
    Http::fake([
        'sneaky.example.com/*' => Http::response('', 302, ['Location' => 'http://internal.example.com/secret']),
        'internal.example.com/*' => Http::response(($this->page)('<title>Secret</title>')),
    ]);

    expect(($this->preview)('https://sneaky.example.com/go')->ok)->toBeFalse();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'internal.example.com'));
});

test('a normal redirect is followed, but at most 3 times', function () {
    Http::fake([
        'sneaky.example.com/a' => Http::response('', 301, ['Location' => '/b']),
        'sneaky.example.com/b' => Http::response('', 301, ['Location' => 'https://shop.example.com/final']),
        'shop.example.com/final' => Http::response(($this->page)('<title>Landed</title>')),
        'sneaky.example.com/loop' => Http::response('', 302, ['Location' => '/loop']),
    ]);

    expect(($this->preview)('https://sneaky.example.com/a')->title)->toBe('Landed')
        ->and(($this->preview)('https://sneaky.example.com/loop')->ok)->toBeFalse();
});

// Shop names and the pasted description

test('the shop name comes from the address', function (string $url, ?string $shop) {
    expect(ShopName::from($url))->toBe($shop);
})->with([
    ['https://shopee.ph/x', 'Shopee'],
    ['https://www.lazada.com.ph/products/x', 'Lazada'],
    ['https://shop.tiktok.com/x', 'TikTok Shop'],
    ['https://www.example-store.ph/x', 'example-store.ph'],
    ['not a link', null],
]);

test('a pasted description gives a title and a peso price', function (string $text, ?string $title, ?int $cents) {
    $parsed = DescriptionParser::parse($text);

    expect($parsed['title'])->toBe($title)->and($parsed['price']?->cents())->toBe($cents);
})->with([
    'peso sign' => ["\n  Klairs Vitamin C Serum 35ml\nOnly ₱1,150.50 today!", 'Klairs Vitamin C Serum 35ml', 115050],
    'PHP' => ["Sunscreen SPF50\nPrice: PHP 499", 'Sunscreen SPF50', 49900],
    'no price' => ['Just a name', 'Just a name', null],
    'numbers without currency are ignored' => ["Pack of 3\n200 ml", 'Pack of 3', null],
    'empty' => ['   ', null, null],
]);
