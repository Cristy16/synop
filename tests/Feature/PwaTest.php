<?php

use App\Models\User;

test('the web manifest is valid and points at real icons', function () {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('Synop')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe('/today')
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('any', 'maskable');

    foreach ($manifest['icons'] as $icon) {
        $size = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect($size)->not->toBeFalse()
            ->and($size[0].'x'.$size[1])->toBe($icon['sizes']);
    }
});

test('the service worker and offline page exist, and pages are never cached', function () {
    $sw = (string) file_get_contents(public_path('sw.js'));

    expect(file_exists(public_path('offline.html')))->toBeTrue()
        ->and($sw)->toContain("'/offline.html'")
        ->and($sw)->toContain("request.mode === 'navigate'");
});

test('app pages link the manifest, show the bottom navigation and the install hint', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('today'))
        ->assertOk()
        ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)
        ->assertSee('name="theme-color"', false)
        ->assertSee('apple-mobile-web-app-capable', false)
        ->assertSee('data-test="bottom-nav"', false)
        ->assertSee('data-test="install-hint"', false);
});

test('sign-in pages link the manifest too, without the app navigation', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertDontSee('data-test="bottom-nav"', false);
});

test('pages send browser safety headers', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('today'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($this->get(route('login'))->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
