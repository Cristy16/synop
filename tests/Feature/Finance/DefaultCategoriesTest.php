<?php

use App\Actions\Finance\CreateDefaultCategories;
use App\Enums\CategoryKind;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

test('a new user gets the starter categories when registering', function () {
    $this->post(route('register.store'), [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'ana@example.com')->sole();
    $categories = TransactionCategory::forUser($user)->get();

    expect($categories->where('kind', CategoryKind::Expense)->pluck('name')->all())
        ->toEqualCanonicalizing(CreateDefaultCategories::DEFAULTS['expense'])
        ->and($categories->where('kind', CategoryKind::Income)->pluck('name')->all())
        ->toEqualCanonicalizing(CreateDefaultCategories::DEFAULTS['income']);
});

test('creating the defaults twice adds nothing', function () {
    $user = User::factory()->create();

    app(CreateDefaultCategories::class)->handle($user);
    app(CreateDefaultCategories::class)->handle($user);

    expect(TransactionCategory::forUser($user)->count())->toBe(13);
});

test('the defaults keep categories the user already has', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    TransactionCategory::factory()->create(['name' => 'Food']);
    TransactionCategory::factory()->create(['name' => 'Milk tea']);
    Auth::logout();

    app(CreateDefaultCategories::class)->handle($user);

    expect(TransactionCategory::forUser($user)->count())->toBe(14);
});

test('the backfill migration gives existing users the defaults without duplicates', function () {
    [$a, $b] = User::factory()->count(2)->create();
    app(CreateDefaultCategories::class)->handle($a); // a already has them

    $migration = require database_path('migrations/2026_11_18_034018_add_default_transaction_categories_to_existing_users.php');
    $migration->up();

    expect(TransactionCategory::forUser($a)->count())->toBe(13)
        ->and(TransactionCategory::forUser($b)->count())->toBe(13);
});

test('the backfill list matches the app\'s starter list', function () {
    $user = User::factory()->create();

    $migration = require database_path('migrations/2026_11_18_034018_add_default_transaction_categories_to_existing_users.php');
    $migration->up();

    $rows = DB::table('transaction_categories')->where('user_id', $user->id)->get();

    expect($rows->where('kind', 'expense')->pluck('name')->all())->toEqualCanonicalizing(CreateDefaultCategories::DEFAULTS['expense'])
        ->and($rows->where('kind', 'income')->pluck('name')->all())->toEqualCanonicalizing(CreateDefaultCategories::DEFAULTS['income']);
});
