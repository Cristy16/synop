<?php

use App\Actions\Wants\ChangeWantStatus;
use App\Actions\Wants\CreateWant;
use App\Actions\Wants\DeleteWant;
use App\Actions\Wants\MarkWantBought;
use App\Actions\Wants\UpdateWant;
use App\Enums\FetchStatus;
use App\Enums\TransactionType;
use App\Enums\WantStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Models\Want;
use App\Services\AccountBalances;
use App\Services\LinkPreview\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\FakeHostResolver;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
    app()->instance(HostResolver::class, new FakeHostResolver(['shopee.ph' => ['143.92.88.1'], 'blocked.example.com' => ['93.184.215.14']]));

    $this->gcash = Account::factory()->opening(500000)->create(['name' => 'GCash']); // ₱5,000
    $this->shopping = TransactionCategory::factory()->create(['name' => 'Shopping']);
    $this->want = fn (array $o = []) => app(CreateWant::class)->handle($this->user, ['title' => 'Snail essence', 'price' => '1,299', ...$o]);
    $this->buy = fn (Want $w, array $o = []) => app(MarkWantBought::class)->handle($this->user, $w, [
        'bought_on' => '2026-11-19', 'record_expense' => true, 'account_id' => $this->gcash->id, 'category_id' => $this->shopping->id, ...$o,
    ]);
    $this->balance = fn () => app(AccountBalances::class)->of($this->gcash)->cents();
});

// Saving

test('a want is saved with its details, shop from the link', function () {
    $want = ($this->want)(['url' => 'https://shopee.ph/x', 'image_url' => 'https://cf.shopee.ph/a.jpg', 'priority' => 'high', 'fetch_status' => 'fetched']);
    $fresh = Want::findOrFail($want->id);

    expect($fresh->user_id)->toBe($this->user->id)
        ->and($fresh->shop)->toBe('Shopee')
        ->and($fresh->price_cents->cents())->toBe(129900)
        ->and($fresh->status)->toBe(WantStatus::Wanted)
        ->and($fresh->fetch_status)->toBe(FetchStatus::Fetched);
});

test('without a link it counts as typed by hand', function () {
    expect(($this->want)(['fetch_status' => 'fetched'])->fetch_status)->toBe(FetchStatus::Manual);
});

test('invalid wants are rejected', function (array $o, string $field) {
    expect(fn () => ($this->want)($o))->toThrow(function (ValidationException $e) use ($field) {
        expect($e->errors())->toHaveKey($field);
    });

    expect(Want::count())->toBe(0);
})->with([
    'no title' => [['title' => ''], 'title'],
    'not a link' => [['url' => 'shopee dot ph'], 'url'],
    'javascript link' => [['url' => 'javascript:alert(1)'], 'url'],
    'negative price' => [['price' => '-5'], 'price'],
    'http image' => [['image_url' => 'http://img.example.com/a.jpg'], 'image_url'],
    'unknown type' => [['product_type' => 'gadget'], 'product_type'],
]);

test('the spending category must be one of your expense categories', function () {
    $income = TransactionCategory::factory()->income()->create();

    expect(fn () => ($this->want)(['expense_category_id' => $income->id]))->toThrow(ValidationException::class);
});

// Bought, with an expense

test('marking bought can record the price as an expense', function () {
    $want = ($this->want)();

    ($this->buy)($want);

    $expense = Transaction::sole();
    expect($want->fresh())->status->toBe(WantStatus::Bought)->bought_on->toDateString()->toBe('2026-11-19')
        ->and($expense->type)->toBe(TransactionType::Expense)
        ->and($expense->source_type)->toBe('want')
        ->and($expense->amount_cents->cents())->toBe(129900)
        ->and($expense->note)->toBe('Snail essence')
        ->and(($this->balance)())->toBe(370100)
        ->and(Gate::allows('update', $expense))->toBeFalse('changed only through the want');
});

test('marking bought without an expense moves no money', function () {
    ($this->buy)(($this->want)(), ['record_expense' => false]);

    expect(Transaction::count())->toBe(0)->and(Want::sole()->status)->toBe(WantStatus::Bought);
});

test('an expense needs a price above zero and your own account', function () {
    $free = ($this->want)(['price' => '']);
    expect(fn () => ($this->buy)($free))->toThrow(ValidationException::class);

    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Account::factory()->create();
    $this->actingAs($this->user);

    expect(fn () => ($this->buy)(($this->want)(), ['account_id' => $theirs->id]))->toThrow(ValidationException::class);
    expect(Transaction::count())->toBe(0);
});

test('editing a bought want keeps its expense in step', function () {
    $want = ($this->want)();
    ($this->buy)($want);

    app(UpdateWant::class)->handle($this->user, $want, ['title' => 'Snail essence 150ml', 'price' => '1,499']);

    expect(Transaction::sole())->amount_cents->cents()->toBe(149900)->note->toBe('Snail essence 150ml')
        ->and(fn () => app(UpdateWant::class)->handle($this->user, $want->fresh(), ['title' => 'X', 'price' => '']))->toThrow(ValidationException::class);
});

test('moving a bought want back to the list removes its expense', function () {
    $want = ($this->want)();
    ($this->buy)($want);

    app(ChangeWantStatus::class)->handle($this->user, $want->fresh(), WantStatus::Wanted);

    expect($want->fresh())->status->toBe(WantStatus::Wanted)->bought_on->toBeNull()
        ->and(Transaction::count())->toBe(0)
        ->and(($this->balance)())->toBe(500000);
});

test('a want can be dropped and wanted again; a bought one can\'t be dropped directly', function () {
    $want = ($this->want)();

    app(ChangeWantStatus::class)->handle($this->user, $want, WantStatus::Dropped);
    expect($want->fresh()->status)->toBe(WantStatus::Dropped);

    app(ChangeWantStatus::class)->handle($this->user, $want->fresh(), WantStatus::Wanted);
    ($this->buy)($want->fresh());

    expect(fn () => app(ChangeWantStatus::class)->handle($this->user, $want->fresh(), WantStatus::Dropped))->toThrow(ValidationException::class);
});

// Delete

test('deleting a bought want asks about the money: put it back, or keep the expense', function (bool $putBack, int $transactions, int $balance) {
    $want = ($this->want)();
    ($this->buy)($want);

    app(DeleteWant::class)->handle($this->user, $want->fresh(), $putBack);

    expect(Want::count())->toBe(0)
        ->and(Transaction::count())->toBe($transactions)
        ->and(($this->balance)())->toBe($balance);

    if (! $putBack) {
        expect(Transaction::sole()->source_type)->toBeNull();
    }
})->with([
    'yes, put it back' => [true, 0, 500000],
    'no, keep the expense' => [false, 1, 370100],
]);

// Page

test('the Wants page loads, is in the sidebar, and guests are sent to login', function () {
    $this->get(route('wants.index'))->assertOk()->assertSee(route('wants.index'));

    auth()->logout();
    $this->get(route('wants.index'))->assertRedirect(route('login'));
});

test('pasting a link fills the details from the page', function () {
    Http::fake(['shopee.ph/*' => Http::response('<html><head><meta property="og:title" content="Snail essence"><meta property="og:price:amount" content="1299"><meta property="og:image" content="https://cf.shopee.ph/a.jpg"></head></html>')]);

    Livewire::test('pages::wants.index')
        ->call('create')
        ->set('url', 'https://shopee.ph/snail')
        ->assertSet('title', 'Snail essence')
        ->assertSet('price', '1299.00')
        ->assertSet('shop', 'Shopee')
        ->assertSet('fetch_status', 'fetched')
        ->assertSee('Details filled in from the page')
        ->call('save')
        ->assertHasNoErrors();

    expect(Want::sole())->fetch_status->toBe(FetchStatus::Fetched)->image_url->toBe('https://cf.shopee.ph/a.jpg');
});

test('a blocked page shows a friendly message, and manual entry still works', function () {
    Http::fake(['blocked.example.com/*' => Http::response('Access denied', 403)]);

    Livewire::test('pages::wants.index')
        ->call('create')
        ->set('url', 'https://blocked.example.com/item')
        ->assertSet('fetch_status', 'failed')
        ->assertSee('Couldn')
        ->set('title', 'Typed by hand')
        ->set('price', '850')
        ->call('save')
        ->assertHasNoErrors();

    expect(Want::sole())->title->toBe('Typed by hand')->fetch_status->toBe(FetchStatus::Failed);
});

test('reading links is limited to 20 a minute per user; past that, the details are typed', function () {
    Http::fake(['shopee.ph/*' => Http::response('<html><head><title>Item</title></head></html>')]);
    $page = Livewire::test('pages::wants.index')->call('create');

    for ($i = 1; $i <= 20; $i++) {
        $page->set('url', 'https://shopee.ph/item-'.$i)->assertSet('fetch_status', 'fetched');
    }

    $page->set('url', 'https://shopee.ph/item-21')
        ->assertSet('fetch_status', 'manual')
        ->assertSet('shop', 'Shopee')
        ->assertSee('Too many links at once');
    Http::assertSentCount(20);

    // Someone else isn't affected.
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::wants.index')->call('create')->set('url', 'https://shopee.ph/other')->assertSet('fetch_status', 'fetched');

    // A minute later it works again.
    $this->actingAs($this->user);
    $this->travel(61)->seconds();
    $page->set('url', 'https://shopee.ph/item-22')->assertSet('fetch_status', 'fetched');
});

test('pasting the description fills an empty title and price, but never overwrites', function () {
    Livewire::test('pages::wants.index')
        ->call('create')
        ->set('description', "Klairs Vitamin C Serum\nNow ₱1,150.00")
        ->assertSet('title', 'Klairs Vitamin C Serum')
        ->assertSet('price', '1150.00')
        ->set('description', "Something else\n₱9.00")
        ->assertSet('title', 'Klairs Vitamin C Serum');
});

test('mark bought from the page records the expense', function () {
    $want = ($this->want)(['expense_category_id' => $this->shopping->id]);

    Livewire::test('pages::wants.index')
        ->call('askBought', $want->id)
        ->assertSet('record_expense', true)
        ->assertSet('category_id', (string) $this->shopping->id)
        ->call('markBought')
        ->assertHasNoErrors()
        ->set('status', 'bought')
        ->assertSee('Snail essence');

    expect(Transaction::sole()->amount_cents->cents())->toBe(129900);
});

test('the page asks about the money before deleting a bought want', function () {
    $want = ($this->want)();
    ($this->buy)($want);

    Livewire::test('pages::wants.index')
        ->call('askDelete', $want->id)
        ->assertSee('Was this entry a mistake?')
        ->call('delete', true);

    expect(Want::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

// Cross-user

test('a want is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => app(CreateWant::class)->handle($owner, ['title' => 'Mine']));
});

test('another user gets a 404 from every want action', function (Closure $call) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $want = app(CreateWant::class)->handle($owner, ['title' => 'Theirs', 'price' => '100']);
    $this->actingAs($this->user);

    expect(fn () => $call($this->user, $want))->toThrow(function (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    });

    $this->actingAs($owner);
    expect(Want::sole())->title->toBe('Theirs')->status->toBe(WantStatus::Wanted);
})->with([
    'edit' => fn (User $u, Want $w) => app(UpdateWant::class)->handle($u, $w, ['title' => 'Hacked']),
    'mark bought' => fn (User $u, Want $w) => app(MarkWantBought::class)->handle($u, $w, ['bought_on' => '2026-11-19']),
    'drop' => fn (User $u, Want $w) => app(ChangeWantStatus::class)->handle($u, $w, WantStatus::Dropped),
    'delete' => fn (User $u, Want $w) => app(DeleteWant::class)->handle($u, $w, true),
]);

test('another user\'s want ids return 404 on the page', function (string $method) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $want = app(CreateWant::class)->handle($owner, ['title' => 'Their secret want']);
    $this->actingAs($this->user);

    Livewire::test('pages::wants.index')->call($method, $want->id)->assertNotFound();
    $this->get(route('wants.index'))->assertDontSee('Their secret want');
})->with(['edit', 'askBought', 'askDelete']);

test('locked ids can\'t be changed from the browser', function (string $property) {
    Livewire::test('pages::wants.index')->set($property, 1);
})->with(['editingId', 'buyingId', 'deletingId'])->throws(CannotUpdateLockedPropertyException::class);
