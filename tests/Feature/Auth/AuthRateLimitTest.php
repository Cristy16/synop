<?php

use App\Models\User;

// Audit findings 3 and 4 (D-054).

test('at most 10 new accounts an hour from one network address', function () {
    $register = fn (int $i) => $this->post(route('register.store'), [
        'name' => 'Person '.$i,
        'email' => "person{$i}@example.com",
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    for ($i = 1; $i <= 10; $i++) {
        $register($i)->assertSessionHasNoErrors();
        auth()->logout();
    }

    $register(11)->assertSessionHasErrors(['email' => 'Too many new accounts from this network. Try again in 60 minutes.']);
    expect(User::count())->toBe(10);

    $this->travel(61)->minutes();
    $register(12)->assertSessionHasNoErrors();
});

test('failed sign-ups don\'t use up the limit', function () {
    for ($i = 1; $i <= 12; $i++) {
        $this->post(route('register.store'), ['name' => 'X', 'email' => 'not-an-email', 'password' => 'p', 'password_confirmation' => 'q']);
    }

    $this->post(route('register.store'), [
        'name' => 'Real', 'email' => 'real@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();
});

test('5 wrong password confirmations a minute, then a wait', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($i = 1; $i <= 5; $i++) {
        $this->post(route('password.confirm.store'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    }

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertSessionHasErrors(['password' => 'Too many tries. Wait 60 seconds and try again.']);

    $this->travel(61)->seconds();
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertSessionHasNoErrors();
});

test('the right password still confirms straight away', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertSessionHasNoErrors();
});
