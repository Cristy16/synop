<?php

use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\DeleteProduct;
use App\Actions\PersonalCare\ProductBookkeeping;
use App\Actions\PersonalCare\RestoreProduct;
use App\Actions\PersonalCare\UpdateProduct;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\AccountBalances;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->gcash = Account::factory()->opening(500000)->create(['name' => 'GCash']); // ₱5,000
    $this->care = TransactionCategory::factory()->create(['name' => 'Personal Care']);

    $this->input = fn (array $overrides = []) => [
        'type' => 'skincare', 'brand' => 'Belo', 'name' => 'Sunscreen', 'category' => 'sunscreen',
        'price' => '650', 'purchased_on' => '2026-11-10', 'status' => 'unopened', ...$overrides,
    ];
    $this->create = fn (array $overrides = []) => app(CreateProduct::class)->handle($this->user, ($this->input)($overrides));
    $this->withExpense = ['record_expense' => true, 'account_id' => null, 'category_id' => null];
    $this->expense = fn (array $overrides = []) => [
        'record_expense' => true, 'account_id' => $this->gcash->id, 'category_id' => $this->care->id, ...$overrides,
    ];
    $this->balance = fn () => app(AccountBalances::class)->of($this->gcash)->cents();
});

// Expiry (D-016)

test('expires_on is the earlier of the printed expiry and opened + PAO', function (?string $printed, ?string $opened, ?int $pao, ?string $expected) {
    $product = ($this->create)(['printed_expiry_on' => $printed, 'opened_on' => $opened, 'pao_months' => $pao]);

    expect($product->fresh()->expires_on?->toDateString())->toBe($expected);
})->with([
    'nothing to go on' => [null, null, null, null],
    'printed only' => ['2027-06-30', null, null, '2027-06-30'],
    'opened + PAO only' => [null, '2026-11-15', 12, '2027-11-15'],
    'PAO but never opened' => [null, null, 12, null],
    'both: PAO is earlier' => ['2028-01-31', '2026-11-15', 6, '2027-05-15'],
    'both: printed is earlier' => ['2027-02-01', '2026-11-15', 12, '2027-02-01'],
    'month end doesn\'t overflow' => [null, '2027-01-31', 1, '2027-02-28'],
    'leap year month end' => [null, '2028-01-31', 1, '2028-02-29'],
]);

test('editing a product works the expiry out again', function () {
    $product = ($this->create)(['opened_on' => '2026-11-15', 'pao_months' => 12]);

    app(UpdateProduct::class)->handle($this->user, $product, ($this->input)(['opened_on' => '2026-11-15', 'pao_months' => 6]));

    expect($product->fresh()->expires_on->toDateString())->toBe('2027-05-15');
});

// Saving

test('a product is saved with its fields', function () {
    $product = ($this->create)(['price' => '₱1,299.50', 'quantity' => 2, 'ingredients_text' => 'Water, Niacinamide']);
    $fresh = Product::findOrFail($product->id);

    expect($fresh->user_id)->toBe($this->user->id)
        ->and($fresh->type)->toBe(ProductType::Skincare)
        ->and($fresh->displayName())->toBe('Belo Sunscreen')
        ->and($fresh->getRawOriginal('price_cents'))->toBe(129950)
        ->and($fresh->quantity)->toBe(2)
        ->and($fresh->status)->toBe(ProductStatus::Unopened)
        ->and($fresh->ingredients_text)->toBe('Water, Niacinamide');
});

test('invalid products are rejected', function (array $overrides, string $field) {
    expect(fn () => ($this->create)($overrides))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Product::count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'unknown type' => [['type' => 'haircare'], 'type'],
    'unknown status' => [['status' => 'lost'], 'status'],
    'negative price' => [['price' => '-1'], 'price'],
    'not a price' => [['price' => 'cheap'], 'price'],
    'opened before bought' => [['opened_on' => '2026-11-09'], 'opened_on'],
    'PAO 0 months' => [['pao_months' => 0], 'pao_months'],
    'PAO over 120 months' => [['pao_months' => 121], 'pao_months'],
    'tablets left on skincare' => [['units_left' => 30], 'units_left'],
    'low-stock level on makeup' => [['type' => 'makeup', 'low_stock_threshold' => 5], 'low_stock_threshold'],
]);

test('vitamins can have tablets left and a low-stock level', function () {
    $product = ($this->create)(['type' => 'vitamin', 'name' => 'Vitamin C', 'units_left' => 60, 'low_stock_threshold' => 10]);

    expect($product->fresh())->units_left->toBe(60)->low_stock_threshold->toBe(10);
});

// Record as expense

test('recording it as an expense takes the price out of the account', function () {
    $product = ($this->create)(($this->expense)());

    $transaction = Transaction::sole();

    expect($transaction->type)->toBe(TransactionType::Expense)
        ->and($transaction->source_type)->toBe('product')
        ->and($transaction->source_id)->toBe($product->id)
        ->and($transaction->amount_cents->cents())->toBe(65000)
        ->and($transaction->occurred_on->toDateString())->toBe('2026-11-10')
        ->and($transaction->category_id)->toBe($this->care->id)
        ->and($transaction->note)->toBe('Belo Sunscreen')
        ->and(($this->balance)())->toBe(435000);
});

test('an expense needs a price, a purchase date, your account and an expense category', function (array $overrides, string $field) {
    expect(fn () => ($this->create)(($this->expense)($overrides)))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Product::count())->toBe(0)->and(Transaction::count())->toBe(0);
})->with([
    'no price' => [['price' => ''], 'price'],
    'price zero' => [['price' => '0'], 'price'],
    'no purchase date' => [['purchased_on' => null], 'purchased_on'],
    'no account' => [['account_id' => null], 'account_id'],
    'no category' => [['category_id' => null], 'category_id'],
]);

test('an income category can\'t be used for the expense', function () {
    $allowance = TransactionCategory::factory()->income()->create();

    expect(fn () => ($this->create)(($this->expense)(['category_id' => $allowance->id])))->toThrow(ValidationException::class);
});

test('editing the product keeps its expense in step', function () {
    $product = ($this->create)(($this->expense)());
    $bpi = Account::factory()->create(['name' => 'BPI']);

    app(UpdateProduct::class)->handle($this->user, $product, ($this->input)([...($this->expense)(['account_id' => $bpi->id]), 'price' => '700', 'purchased_on' => '2026-11-12', 'name' => 'Sunscreen SPF50']));

    $transaction = Transaction::sole();
    expect($transaction->amount_cents->cents())->toBe(70000)
        ->and($transaction->account_id)->toBe($bpi->id)
        ->and($transaction->occurred_on->toDateString())->toBe('2026-11-12')
        ->and($transaction->note)->toBe('Belo Sunscreen SPF50')
        ->and(($this->balance)())->toBe(500000);
});

test('unticking "record as expense" removes it; ticking it later adds it', function () {
    $product = ($this->create)(($this->expense)());

    app(UpdateProduct::class)->handle($this->user, $product, ($this->input)());
    expect(Transaction::count())->toBe(0)->and(($this->balance)())->toBe(500000);

    app(UpdateProduct::class)->handle($this->user, $product, ($this->input)(($this->expense)()));
    expect(Transaction::count())->toBe(1)->and(($this->balance)())->toBe(435000);
});

test('a product\'s expense can\'t be edited from Finance', function () {
    ($this->create)(($this->expense)());

    expect(Gate::inspect('update', Transaction::sole())->message())
        ->toBe('This transaction was made by a product. Change the product instead.');
});

// Delete and restore (D-045)

test('deleting with "put the money back" hides the expense too, and restore brings both back', function () {
    $product = ($this->create)(($this->expense)());

    app(DeleteProduct::class)->handle($this->user, $product, putMoneyBack: true);

    expect(Product::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and(($this->balance)())->toBe(500000);

    app(RestoreProduct::class)->handle($this->user, Product::onlyTrashed()->findOrFail($product->id));

    expect(Product::count())->toBe(1)
        ->and(Transaction::sole()->source_id)->toBe($product->id)
        ->and(($this->balance)())->toBe(435000);
});

test('deleting with "keep the expense" leaves it as a normal, editable expense', function () {
    $product = ($this->create)(($this->expense)());

    app(DeleteProduct::class)->handle($this->user, $product, putMoneyBack: false);

    $transaction = Transaction::sole();
    expect(Product::count())->toBe(0)
        ->and($transaction->source_type)->toBeNull()
        ->and(Gate::allows('update', $transaction))->toBeTrue()
        ->and(($this->balance)())->toBe(435000);

    app(RestoreProduct::class)->handle($this->user, Product::onlyTrashed()->findOrFail($product->id));
    expect(Transaction::count())->toBe(1)
        ->and(app(ProductBookkeeping::class)->expenseOf($product->fresh()))->toBeNull('the kept expense stays its own');
});

test('a product without an expense is simply hidden', function () {
    $product = ($this->create)();

    app(DeleteProduct::class)->handle($this->user, $product, putMoneyBack: true);

    expect(Product::count())->toBe(0)->and(Product::withTrashed()->count())->toBe(1);
});

test('products are deleted with their user', function () {
    ($this->create)();

    $this->user->delete();

    expect(Product::forUser($this->user)->withTrashed()->count())->toBe(0);
});
