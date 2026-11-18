<?php

use App\Actions\Ai\ScanIngredientLabel;
use App\Actions\Finance\CreateDebt;
use App\Actions\PersonalCare\CreateConflictRule;
use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\CreateStarterCareData;
use App\Actions\Wants\AnalyzeWant;
use App\Actions\Wants\CreateWant;
use App\Actions\Wants\UpdateWant;
use App\Enums\AnalysisEngine;
use App\Enums\Verdict;
use App\Models\Account;
use App\Models\AiRequest;
use App\Models\Contact;
use App\Models\IngredientTag;
use App\Models\User;
use App\Models\WantAnalysis;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiQuota;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\GeminiClient;
use App\Services\IngredientCheck;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakeAiClient;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Cristy Secretname']); // Asia/Manila
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->ai = new FakeAiClient;
    app()->instance(AiClient::class, $this->ai);
    Http::preventStrayRequests();

    Account::factory()->opening(1000000)->create(['name' => 'BPI Savings 1234']); // ₱10,000
    $this->want = app(CreateWant::class)->handle($this->user, ['title' => 'Serum', 'price' => '1,150', 'product_type' => 'skincare']);
    $this->analyze = fn () => app(AnalyzeWant::class)->handle($this->user, $this->want->fresh());
});

// The verdict

test('the AI picks the verdict from figures PHP worked out', function () {
    $analysis = ($this->analyze)();

    expect($analysis->engine)->toBe(AnalysisEngine::Ai)
        ->and($analysis->verdict)->toBe(Verdict::BuyNow)
        ->and($analysis->reasons)->toBe(['It fits your budget.', 'You marked it high priority.'])
        ->and($this->ai->verdictCalls)->toHaveCount(1);

    $sent = $this->ai->verdictCalls[0];
    expect($sent['price_cents'])->toBe(115000)
        ->and($sent['safe_to_spend']['safe_cents'])->toBe(1000000)
        ->and($sent['left_after_purchase_cents'])->toBe(885000)
        ->and($sent['left_after_purchase_text'])->toBe('₱8,850.00');
});

test('nothing private is sent: no names, contacts or account details', function () {
    $contact = Contact::factory()->create(['name' => 'Tita Mercedes']);
    app(CreateDebt::class)->handle($this->user, ['contact_id' => $contact->id, 'direction' => 'i_owe', 'principal' => '500', 'started_on' => '2026-11-01', 'due_on' => '2026-11-25']);

    ($this->analyze)();
    $sent = json_encode($this->ai->verdictCalls[0]);

    expect($sent)->not->toContain('Secretname')
        ->not->toContain('Tita Mercedes')
        ->not->toContain('BPI')
        ->not->toContain('1234')
        ->and($this->ai->verdictCalls[0]['safe_to_spend']['owed_before_text'])->toBe('₱500.00');
});

test('the same inputs reuse the cached answer: no call, no quota', function () {
    ($this->analyze)();
    ($this->analyze)();

    expect($this->ai->verdictCalls)->toHaveCount(1)
        ->and(AiRequest::count())->toBe(1)
        ->and(WantAnalysis::count())->toBe(1);

    // A price change is new inputs → a new call.
    app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Serum', 'price' => '1,300', 'product_type' => 'skincare']);
    ($this->analyze)();
    expect($this->ai->verdictCalls)->toHaveCount(2);
});

test('the AI\'s reply is checked; anything odd falls back to the rules', function (array $reply) {
    $this->ai->verdict = $reply;

    $analysis = ($this->analyze)();

    expect($analysis->engine)->toBe(AnalysisEngine::Rules)
        ->and(AiRequest::sole()->succeeded)->toBeFalse();
})->with([
    'unknown verdict' => [['verdict' => 'definitely', 'reasons' => ['a', 'b']]],
    'one reason' => [['verdict' => 'wait', 'reasons' => ['a']]],
    'four reasons' => [['verdict' => 'wait', 'reasons' => ['a', 'b', 'c', 'd']]],
    'blank reasons' => [['verdict' => 'wait', 'reasons' => ['  ', '']]],
]);

test('with no key, a failure, or no calls left, the rules answer', function () {
    $this->ai->configured = false;
    expect(($this->analyze)()->engine)->toBe(AnalysisEngine::Rules)
        ->and($this->ai->verdictCalls)->toBe([])
        ->and(AiRequest::count())->toBe(0, 'no call made, nothing counted');

    $this->ai->configured = true;
    $this->ai->verdict = null;
    app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Serum', 'price' => '1,200', 'product_type' => 'skincare']);
    expect(($this->analyze)()->engine)->toBe(AnalysisEngine::Rules)
        ->and(AiRequest::sole()->succeeded)->toBeFalse('a failed call still counts');
});

test('10 AI calls a day, counted on the user\'s own day', function () {
    for ($i = 1; $i <= 10; $i++) {
        app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Serum', 'price' => (string) (1000 + $i), 'product_type' => 'skincare']);
        expect(($this->analyze)()->engine)->toBe(AnalysisEngine::Ai);
    }

    expect(app(AiQuota::class)->remaining($this->user))->toBe(0);

    app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Serum', 'price' => '2,000', 'product_type' => 'skincare']);
    expect(($this->analyze)()->engine)->toBe(AnalysisEngine::Rules)
        ->and($this->ai->verdictCalls)->toHaveCount(10);

    // Midnight in Manila (4 pm UTC) starts a new day.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 16:00:00', 'UTC'));
    expect(app(AiQuota::class)->remaining($this->user))->toBe(10);
});

// Rules fallback

test('rules: it fits → buy now; too dear → wait; no price → wait', function () {
    $this->ai->configured = false;
    expect(($this->analyze)()->verdict)->toBe(Verdict::BuyNow);

    app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Serum', 'price' => '12,000', 'product_type' => 'skincare']);
    $analysis = ($this->analyze)();
    expect($analysis->verdict)->toBe(Verdict::Wait)
        ->and($analysis->reasons[0])->toContain('₱12,000.00')->toContain('₱10,000.00');

    app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Serum', 'price' => '', 'product_type' => 'skincare']);
    expect(($this->analyze)()->verdict)->toBe(Verdict::Wait);
});

// Ingredient check

test('ingredients are checked against the user\'s own groups and conflict rules', function () {
    app(CreateStarterCareData::class)->handle($this->user);
    $tag = fn (string $name) => IngredientTag::where('name', $name)->sole();
    app(CreateConflictRule::class)->handle($this->user, ['tag_a_id' => $tag('Retinoid')->id, 'tag_b_id' => $tag('AHA')->id, 'scope' => 'same_routine']);
    app(CreateProduct::class)->handle($this->user, ['type' => 'skincare', 'name' => 'Glow toner', 'status' => 'in_use', 'ingredients_text' => 'Water, Glycolic Acid']);
    app(CreateProduct::class)->handle($this->user, ['type' => 'skincare', 'name' => 'Night cream', 'status' => 'in_use', 'ingredients_text' => 'Water, Retinol']);
    app(CreateProduct::class)->handle($this->user, ['type' => 'skincare', 'name' => 'Old retinol', 'status' => 'finished', 'ingredients_text' => 'Retinol']);

    $check = app(IngredientCheck::class)->for($this->user, 'Aqua, Retinol, Squalane');

    expect($check['groups'])->toBe(['Retinoid'])
        ->and($check['conflicts'])->toHaveCount(1)
        ->and($check['conflicts'][0]['rule'])->toBe('AHA ✕ Retinoid')
        ->and($check['conflicts'][0]['owned'])->toBe(['Glow toner'])
        ->and($check['duplicates'][0]['owned'])->toBe(['Night cream'], 'finished products are not counted');

    // The rules fallback uses it.
    $this->ai->configured = false;
    app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Serum', 'price' => '500', 'product_type' => 'skincare', 'ingredients_text' => 'Aqua, Retinol']);
    $analysis = ($this->analyze)();
    expect($analysis->verdict)->toBe(Verdict::Skip)
        ->and(implode(' ', $analysis->reasons))->toContain('AHA ✕ Retinoid')->toContain('already have 1 product with Retinoid');
});

test('no ingredients, or not skincare/makeup → no ingredient check', function () {
    expect(app(IngredientCheck::class)->for($this->user, null))->toBe(['groups' => [], 'conflicts' => [], 'duplicates' => []]);

    app(UpdateWant::class)->handle($this->user, $this->want->fresh(), ['title' => 'Headphones', 'price' => '1,150', 'product_type' => 'other', 'ingredients_text' => 'Retinol']);
    ($this->analyze)();
    expect($this->ai->verdictCalls[0]['ingredients'])->toBeNull();
});

// Label scan

test('a label photo is read into text and counts toward the limit', function () {
    $text = app(ScanIngredientLabel::class)->handle($this->user, 'fake-jpeg-bytes', 'image/jpeg');

    expect($text)->toBe('Aqua, Niacinamide, Glycerin')
        ->and(AiRequest::sole()->kind->value)->toBe('ingredient_scan');
});

test('a label scan that can\'t be done says why, without guessing', function (Closure $setUp, string $mime) {
    $setUp($this);

    expect(fn () => app(ScanIngredientLabel::class)->handle($this->user, 'bytes', $mime))->toThrow(ValidationException::class);
})->with([
    'not an image' => [fn ($t) => null, 'application/pdf'],
    'no key' => [fn ($t) => $t->ai->configured = false, 'image/png'],
    'nothing read' => [fn ($t) => $t->ai->label = '', 'image/png'],
    'AI failed' => [fn ($t) => $t->ai->label = null, 'image/png'],
]);

test('on the page: the photo is read into the box, then deleted', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $photo = UploadedFile::fake()->createWithContent('label.png', $png);

    Livewire::test('pages::wants.index')
        ->call('edit', $this->want->id)
        ->set('labelPhoto', $photo)
        ->assertHasNoErrors()
        ->assertSet('ingredients_text', 'Aqua, Niacinamide, Glycerin')
        ->assertSet('labelPhoto', null);

    expect($this->ai->labelCalls)->toBe(1);
});

// The page

test('the Wants page checks a want and shows the verdict and the AI notice', function () {
    Livewire::test('pages::wants.index')
        ->call('edit', $this->want->id)
        ->assertSee('Should I buy it?')
        ->assertSee('10 AI checks left today')
        ->call('analyze')
        ->assertSee('Buy now')
        ->assertSee('It fits your budget.')
        ->assertSee('Explained by AI')
        ->assertSee('9 AI checks left today')
        ->assertSee('never names, contacts or accounts');
});

// Gemini client

test('the Gemini client sends the key in a header, server-side, and reads JSON back', function () {
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => "```json\n{\"verdict\":\"wait\",\"reasons\":[\"a\",\"b\"]}\n```"]]]]],
    ])]);

    $reply = app(GeminiClient::class)->wantVerdict(['price_cents' => 100]);

    expect($reply)->toBe(['verdict' => 'wait', 'reasons' => ['a', 'b']]);
    Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key')
        && str_contains($request->url(), 'gemini-test:generateContent')
        && ! str_contains($request->url(), 'test-key'));
});

test('the Gemini client turns every problem into AiUnavailable', function (Closure $fake) {
    config(['services.gemini.key' => 'test-key']);
    $fake();

    expect(fn () => app(GeminiClient::class)->wantVerdict([]))->toThrow(AiUnavailable::class);
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response('oops', 500)])],
    'not JSON' => [fn () => Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Sure! Buy it.']]]]]])])],
    'wrong shape' => [fn () => Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"answer": 1}']]]]]])])],
    'timeout' => [fn () => Http::fake(fn () => throw new ConnectionException('timed out'))],
]);

test('with no key the Gemini client makes no request', function () {
    config(['services.gemini.key' => '']);

    expect(fn () => app(GeminiClient::class)->wantVerdict([]))->toThrow(AiUnavailable::class);
    Http::assertNothingSent();
});

// Isolation

test('another user can\'t analyze my want, and never sees my verdicts or AI usage', function () {
    ($this->analyze)();
    $other = User::factory()->create();

    expect(fn () => app(AnalyzeWant::class)->handle($other, $this->want))->toThrow(function (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    });

    $this->actingAs($other);
    expect(WantAnalysis::count())->toBe(0)
        ->and(AiRequest::count())->toBe(0)
        ->and(app(AiQuota::class)->remaining($other))->toBe(10);
});

test('verdicts and AI requests are invisible to other users', function () {
    assertInvisibleToOtherUser(function (User $owner) {
        $want = app(CreateWant::class)->handle($owner, ['title' => 'Lip tint', 'price' => '300']);

        return app(AnalyzeWant::class)->handle($owner, $want);
    });
    assertInvisibleToOtherUser(function (User $owner) {
        app(ScanIngredientLabel::class)->handle($owner, 'bytes', 'image/png');

        return AiRequest::forUser($owner)->sole();
    });
});

test('the product form reads a label photo too', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    Livewire::test('pages::care.products')
        ->set('labelPhoto', UploadedFile::fake()->createWithContent('label.png', $png))
        ->assertHasNoErrors()
        ->assertSet('ingredients_text', 'Aqua, Niacinamide, Glycerin')
        ->assertSet('labelPhoto', null);
});
