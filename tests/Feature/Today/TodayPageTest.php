<?php

use App\Models\User;
use Carbon\CarbonImmutable;

test('guests are redirected to the login page', function () {
    $this->get(route('today'))->assertRedirect(route('login'));
});

test('logging in lands on Today', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('today', absolute: false));
});

test('old dashboard links redirect to Today', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertRedirect('/today');
});

test('Today shows the date in the user\'s timezone and all five sections', function () {
    $this->actingAs(User::factory()->create()); // Asia/Manila
    // 11 pm Nov 18 in UTC is already Thursday Nov 19 in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:00:00', 'UTC'));

    $this->get(route('today'))
        ->assertOk()
        ->assertSee('Thursday, November 19')
        ->assertSeeInOrder(['Tasks', 'Personal Care', 'Vitamins', 'Classes', 'Payments Due'])
        ->assertSee('Nothing due today.')
        ->assertSee('Your AM and PM routine will show here.')
        ->assertSee('Today&#039;s vitamin doses will show here.', false)
        ->assertSee('Today&#039;s classes will show here.', false)
        ->assertSee('Debts due soon will show here.');
});

test('Today is the first link in the sidebar', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertSeeInOrder([route('today'), route('tasks.index')]);
});
