<?php

use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\DeleteProduct;
use App\Actions\PersonalCare\RestoreProduct;
use App\Actions\PersonalCare\UpdateProduct;
use App\Models\Account;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->gcash = Account::factory()->opening(500000)->create(['name' => 'GCash']);
    $this->care = TransactionCategory::factory()->create(['name' => 'Personal Care']);
    TransactionCategory::factory()->create(['name' => 'Food']);
});

test('both Personal Care tabs load, are in the sidebar, and guests are sent to login', function (string $route) {
    $this->get(route($route))->assertOk()->assertSee(route('care.products'));

    auth()->logout();
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['care.products', 'care.spending']);

test('a product is added from the Products tab, as an expense with Personal Care preselected', function () {
    Livewire::test('pages::care.products')
        ->call('create')
        ->assertSet('purchased_on', '2026-11-19')
        ->assertSet('account_id', (string) $this->gcash->id)
        ->assertSet('category_id', (string) $this->care->id)
        ->set('brand', 'Belo')
        ->set('name', 'Sunscreen')
        ->set('price', '650')
        ->set('opened_on', '2026-11-19')
        ->set('pao_months', '6')
        ->set('status', 'in_use')
        ->set('record_expense', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Belo Sunscreen')
        ->assertSee('Expires May 19, 2027');

    expect(Transaction::sole()->amount_cents->cents())->toBe(65000);
});

test('product errors are shown', function () {
    Livewire::test('pages::care.products')
        ->call('create')
        ->set('name', '')
        ->set('price', 'abc')
        ->call('save')
        ->assertHasErrors(['name']);
});

test('the Expiring soon card lists products near their date', function () {
    Product::factory()->create(['brand' => null, 'name' => 'Vitamin C serum', 'expires_on' => '2026-11-24']);
    Product::factory()->create(['brand' => null, 'name' => 'Old toner', 'expires_on' => '2026-11-02']);

    Livewire::test('pages::care.products')
        ->assertSee('Expiring soon')
        ->assertSee('Expires in 5 days')
        ->assertSee('Expired Nov 2');
});

test('filters by type and hides finished products unless asked', function () {
    Product::factory()->create(['brand' => null, 'name' => 'Lip tint', 'type' => 'makeup']);
    Product::factory()->create(['brand' => null, 'name' => 'Empty toner', 'status' => 'finished']);

    Livewire::test('pages::care.products')
        ->assertSee('Lip tint')
        ->assertDontSee('Empty toner')
        ->set('type', 'skincare')
        ->assertDontSee('Lip tint')
        ->set('showAll', true)
        ->assertSee('Empty toner');
});

test('deleting a product with an expense asks whether to put the money back', function (bool $answer, int $transactions) {
    $product = app(CreateProduct::class)->handle($this->user, [
        'type' => 'skincare', 'name' => 'Serum', 'price' => '650', 'purchased_on' => '2026-11-10', 'status' => 'in_use',
        'record_expense' => true, 'account_id' => $this->gcash->id, 'category_id' => $this->care->id,
    ]);

    Livewire::test('pages::care.products')
        ->call('askDelete', $product->id)
        ->assertSee('Was this entry a mistake?')
        ->assertSee('Yes, put ₱650.00 back')
        ->call('delete', $answer);

    expect(Product::count())->toBe(0)->and(Transaction::count())->toBe($transactions);
})->with([
    'yes, put it back' => [true, 0],
    'no, keep the expense' => [false, 1],
]);

test('deleting a product without an expense doesn\'t ask about money', function () {
    $product = Product::factory()->create();

    Livewire::test('pages::care.products')
        ->call('askDelete', $product->id)
        ->assertDontSee('Was this entry a mistake?')
        ->call('delete');

    expect(Product::count())->toBe(0);
});

test('the Spending tab shows this month and the last 6 months per type', function () {
    Product::factory()->create(['type' => 'skincare', 'price_cents' => 65000, 'purchased_on' => '2026-11-10']);
    Product::factory()->create(['type' => 'vitamin', 'price_cents' => 45000, 'purchased_on' => '2026-10-15']);

    Livewire::test('pages::care.spending')
        ->assertSeeInOrder(['This month', '₱650.00'])
        ->assertSeeInOrder(['Nov 2026', '₱650.00', 'Oct 2026', '₱450.00', 'Jun 2026']);
});

test('Finance shows a product\'s expense as "From product"', function () {
    app(CreateProduct::class)->handle($this->user, [
        'type' => 'skincare', 'name' => 'Serum', 'price' => '650', 'purchased_on' => '2026-11-10', 'status' => 'in_use',
        'record_expense' => true, 'account_id' => $this->gcash->id, 'category_id' => $this->care->id,
    ]);

    Livewire::test('pages::finance.transactions')
        ->assertSee('From product')
        ->call('edit', Transaction::sole()->id)
        ->assertSet('editingId', null);
});

// Cross-user

test('a product is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => app(CreateProduct::class)->handle($owner, [
        'type' => 'makeup', 'name' => 'Lip tint', 'status' => 'in_use',
    ]));
});

test('another user gets a 404 from every product action', function (Closure $call) {
    [$owner, $intruder] = User::factory()->count(2)->create();
    $this->actingAs($owner);
    $product = Product::factory()->create(['name' => 'Mine']);
    $snapshot = $product->fresh()->getAttributes();
    $this->actingAs($intruder);

    expect(fn () => $call($intruder, $product))->toThrow(function (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    });

    $this->actingAs($owner);
    expect(Product::findOrFail($product->id)->getAttributes())->toEqual($snapshot);
})->with([
    'update' => fn (User $u, Product $p) => app(UpdateProduct::class)->handle($u, $p, ['type' => 'skincare', 'name' => 'Hacked', 'status' => 'in_use']),
    'delete' => fn (User $u, Product $p) => app(DeleteProduct::class)->handle($u, $p, true),
    'restore' => fn (User $u, Product $p) => app(RestoreProduct::class)->handle($u, $p),
]);

test('another user\'s product ids return 404 on the page', function (string $method) {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Product::factory()->create(['name' => 'Their secret serum']);
    $this->actingAs($this->user);

    Livewire::test('pages::care.products')->call($method, $theirs->id)->assertNotFound();
})->with(['edit', 'askDelete']);

test('a product\'s expense can\'t go into another user\'s account', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirAccount = Account::factory()->create();
    $this->actingAs($this->user);

    Livewire::test('pages::care.products')
        ->call('create')
        ->set('name', 'Serum')
        ->set('price', '650')
        ->set('record_expense', true)
        ->set('account_id', (string) $theirAccount->id)
        ->call('save')
        ->assertHasErrors(['account_id']);

    expect(Product::count())->toBe(0);
});

test('another user\'s products and accounts never show', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    Product::factory()->create(['name' => 'Their secret serum', 'expires_on' => '2026-11-20', 'price_cents' => 99900, 'purchased_on' => '2026-11-10']);
    Account::factory()->create(['name' => 'Their secret bank']);
    $this->actingAs($this->user);

    $this->get(route('care.products'))->assertDontSee('Their secret serum')->assertDontSee('Their secret bank');
    $this->get(route('care.spending'))->assertDontSee('₱999.00');
});

test('locked ids can\'t be changed from the browser', function (string $property) {
    Livewire::test('pages::care.products')->set($property, 1);
})->with(['editingId', 'deletingId'])->throws(CannotUpdateLockedPropertyException::class);
