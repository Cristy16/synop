<?php

use App\Enums\Currency;
use App\Models\User;

test('the app clock runs in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
});

test('a newly registered user gets the default preferences', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'defaults@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'defaults@example.com')->firstOrFail();

    expect($user->timezone)->toBe('Asia/Manila')
        ->and($user->currency)->toBe(Currency::PHP)
        ->and($user->safety_buffer_cents->cents())->toBe(0)
        ->and($user->payments_due_days)->toBe(7)
        ->and($user->morning_summary_time)->toBeNull();
});
