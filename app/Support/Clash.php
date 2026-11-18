<?php

namespace App\Support;

use App\Enums\ConflictScope;
use App\Enums\RoutinePeriod;
use App\Models\ConflictRule;
use App\Models\Product;

/**
 * One broken conflict rule: which products meet, and in which routine
 * (null when the rule is about the whole day).
 */
final readonly class Clash
{
    /**
     * @param  list<Product>  $productsA  products with the rule's first group
     * @param  list<Product>  $productsB  products with the rule's second group
     */
    public function __construct(
        public ConflictRule $rule,
        public array $productsA,
        public array $productsB,
        public ?RoutinePeriod $period,
    ) {}

    /**
     * "AHA + Retinoid in the PM routine: Glycolic toner and Retinol serum".
     */
    public function summary(): string
    {
        $names = fn (array $products) => implode(', ', array_map(fn (Product $p) => $p->displayName(), $products));
        $where = $this->rule->scope === ConflictScope::SameRoutine && $this->period !== null
            ? __('in the :period routine', ['period' => $this->period->label()])
            : __('on the same day');

        return __(':a + :b :where: :productsA and :productsB', [
            'a' => $this->rule->tagA->name,
            'b' => $this->rule->tagB->name,
            'where' => $where,
            'productsA' => $names($this->productsA),
            'productsB' => $names($this->productsB),
        ]);
    }
}
