<?php

use App\Models\User;

test('the app clock runs in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
});

test('a newly registered user gets the Asia/Manila timezone and PHP currency', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'defaults@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'defaults@example.com')->firstOrFail();

    expect($user->timezone)->toBe('Asia/Manila')
        ->and($user->currency)->toBe('PHP');
});
