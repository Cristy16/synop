<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;
use App\Services\CareSummary;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Nov 19, 2026 → "expiring soon" runs to Dec 3.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
    $this->summary = app(CareSummary::class);
});

test('expiring soon: within 14 days or already expired, soonest first, only products still in use', function () {
    Product::factory()->create(['name' => 'Expired', 'expires_on' => '2026-11-02']);
    Product::factory()->create(['name' => 'Today', 'expires_on' => '2026-11-19']);
    Product::factory()->create(['name' => 'Day 14', 'expires_on' => '2026-12-03']);
    Product::factory()->create(['name' => 'Day 15', 'expires_on' => '2026-12-04']);
    Product::factory()->create(['name' => 'No expiry', 'expires_on' => null]);
    Product::factory()->create(['name' => 'Unopened', 'expires_on' => '2026-11-25', 'status' => ProductStatus::Unopened]);
    Product::factory()->create(['name' => 'Finished', 'expires_on' => '2026-11-20', 'status' => ProductStatus::Finished]);
    Product::factory()->create(['name' => 'Discarded', 'expires_on' => '2026-11-20', 'status' => ProductStatus::Discarded]);

    expect($this->summary->expiringSoon($this->user)->pluck('name')->all())
        ->toBe(['Expired', 'Today', 'Unopened', 'Day 14']);
});

test('monthly spending adds prices per type and month, newest month first', function () {
    Product::factory()->create(['type' => ProductType::Skincare, 'price_cents' => 65000, 'purchased_on' => '2026-11-10']);
    Product::factory()->create(['type' => ProductType::Skincare, 'price_cents' => 35050, 'purchased_on' => '2026-11-01']);
    Product::factory()->create(['type' => ProductType::Makeup, 'price_cents' => 29900, 'purchased_on' => '2026-11-18', 'status' => ProductStatus::Finished]);
    Product::factory()->create(['type' => ProductType::Vitamin, 'price_cents' => 45000, 'purchased_on' => '2026-10-31']);
    Product::factory()->create(['type' => ProductType::Skincare, 'price_cents' => 99900, 'purchased_on' => '2026-05-31']); // 7 months ago
    Product::factory()->create(['type' => ProductType::Skincare, 'price_cents' => null, 'purchased_on' => '2026-11-05']); // no price
    Product::factory()->create(['type' => ProductType::Skincare, 'price_cents' => 10000, 'purchased_on' => null]); // no date

    $months = $this->summary->monthlySpending($this->user);
    $cents = fn (array $row) => [array_map(fn ($m) => $m->cents(), $row['types']), $row['total']->cents()];

    expect(array_column($months, 'month'))->toBe(['2026-11', '2026-10', '2026-09', '2026-08', '2026-07', '2026-06'])
        ->and($cents($months[0]))->toBe([['skincare' => 100050, 'makeup' => 29900, 'vitamin' => 0], 129950])
        ->and($cents($months[1]))->toBe([['skincare' => 0, 'makeup' => 0, 'vitamin' => 45000], 45000])
        ->and($cents($months[5]))->toBe([['skincare' => 0, 'makeup' => 0, 'vitamin' => 0], 0]);
});

test('deleted products don\'t count', function () {
    Product::factory()->create(['price_cents' => 65000, 'purchased_on' => '2026-11-10', 'expires_on' => '2026-11-20'])->delete();

    expect($this->summary->monthlySpending($this->user)[0]['total']->isZero())->toBeTrue()
        ->and($this->summary->expiringSoon($this->user))->toHaveCount(0);
});

test('another user\'s products never count', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    Product::factory()->create(['price_cents' => 65000, 'purchased_on' => '2026-11-10', 'expires_on' => '2026-11-20']);
    $this->actingAs($this->user);

    expect($this->summary->monthlySpending($this->user)[0]['total']->isZero())->toBeTrue()
        ->and($this->summary->expiringSoon($this->user))->toHaveCount(0);
});
