<?php

use App\Enums\Currency;
use App\Models\User;
use Livewire\Livewire;

test('the preferences page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('preferences.edit'))
        ->assertOk()
        ->assertSee('Asia/Manila')
        ->assertSee('Amounts are not converted');
});

test('guests are redirected to the login page', function () {
    $this->get(route('preferences.edit'))->assertRedirect(route('login'));
});

test('the form is filled with the current preferences', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.preferences')
        ->assertSet('timezone', 'Asia/Manila')
        ->assertSet('currency', 'PHP')
        ->assertSet('safety_buffer', '0.00')
        ->assertSet('payments_due_days', '7');
});

test('preferences can be updated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.preferences')
        ->set('timezone', 'Asia/Singapore')
        ->set('currency', 'SGD')
        ->set('safety_buffer', '1,500.50')
        ->set('payments_due_days', '14')
        ->call('updatePreferences')
        ->assertHasNoErrors()
        ->assertSet('safety_buffer', '1500.50');

    $user->refresh();

    expect($user->timezone)->toBe('Asia/Singapore')
        ->and($user->currency)->toBe(Currency::SGD)
        ->and($user->safety_buffer_cents->cents())->toBe(150050)
        ->and($user->payments_due_days)->toBe(14);
});

test('amounts are shown in the user\'s currency', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(money(150050))->toBe('₱1,500.50');

    $user->currency = Currency::USD;
    $user->save();

    expect(money(150050))->toBe('$1,500.50')
        ->and(money(150050, Currency::EUR))->toBe('€1,500.50');
});

test('invalid preferences are rejected', function (string $field, string $value) {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.preferences')
        ->set($field, $value)
        ->call('updatePreferences')
        ->assertHasErrors($field);

    expect($user->refresh()->timezone)->toBe('Asia/Manila');
})->with([
    'unknown timezone' => ['timezone', 'Mars/Olympus'],
    'unknown currency' => ['currency', 'JPY'],
    'negative buffer' => ['safety_buffer', '-100'],
    'garbled buffer' => ['safety_buffer', '12 34'],
    'empty buffer' => ['safety_buffer', ''],
    'zero days' => ['payments_due_days', '0'],
    'too many days' => ['payments_due_days', '31'],
    'not a number' => ['payments_due_days', 'abc'],
]);

test('saving only changes the logged-in user', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $this->actingAs($a);

    Livewire::test('pages::settings.preferences')
        ->set('timezone', 'Europe/London')
        ->set('currency', 'GBP')
        ->set('safety_buffer', '99')
        ->call('updatePreferences')
        ->assertHasNoErrors();

    $b->refresh();

    expect($b->timezone)->toBe('Asia/Manila')
        ->and($b->currency)->toBe(Currency::PHP)
        ->and($b->safety_buffer_cents->cents())->toBe(0);
});
