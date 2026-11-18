<?php

namespace App\Services;

use App\Enums\Verdict;
use Carbon\CarbonImmutable;

/**
 * The verdict without the AI: used when the AI is unavailable or the daily limit is
 * reached (ARCHITECTURE §7). Plain rules over the same PHP-computed inputs. Read-only.
 */
class RulesVerdict
{
    /**
     * @param  array<string, mixed>  $inputs  from WantAdvisorInputs
     * @return array{verdict: Verdict, reasons: list<string>}
     */
    public static function decide(array $inputs): array
    {
        $safe = $inputs['safe_to_spend'];
        $until = CarbonImmutable::parse($safe['until'])->format('M j');
        $ingredients = $inputs['ingredients'] ?? null;
        $conflicts = $ingredients['conflicts'] ?? [];
        $duplicates = $ingredients['duplicates'] ?? [];

        if ($inputs['price_cents'] === null) {
            return ['verdict' => Verdict::Wait, 'reasons' => [
                __('Add the price so it can be checked against your budget.'),
                __('You can safely spend :safe until :until.', ['safe' => $safe['safe_text'], 'until' => $until]),
            ]];
        }

        $fits = $inputs['left_after_purchase_cents'] >= 0;
        $money = $fits
            ? __('It fits: :safe is safe to spend until :until, leaving :left.', ['safe' => $safe['safe_text'], 'until' => $until, 'left' => $inputs['left_after_purchase_text']])
            : __('It costs :price, but only :safe is safe to spend until :until.', ['price' => $inputs['price_text'], 'safe' => $safe['safe_text'], 'until' => $until]);

        $reasons = [$money];
        $verdict = $fits ? Verdict::BuyNow : Verdict::Wait;

        if ($conflicts !== []) {
            $reasons[] = __('Its ingredients meet your rule :rule (:scope) with :owned.', [
                'rule' => $conflicts[0]['rule'], 'scope' => $conflicts[0]['scope'], 'owned' => implode(', ', $conflicts[0]['owned']),
            ]);
            $verdict = Verdict::Wait;
        }

        if ($duplicates !== []) {
            $reasons[] = trans_choice('You already have :count product with :group.|You already have :count products with :group.', count($duplicates[0]['owned']), [
                'group' => $duplicates[0]['group'],
            ]);
            $verdict = $fits && $conflicts === [] ? Verdict::Wait : Verdict::Skip;
        }

        if (count($reasons) < 2) {
            $category = $inputs['category'] ?? null;
            $reasons[] = $category !== null
                ? __('You\'ve spent :spent on :category this month.', ['spent' => $category['spent_this_month_text'], 'category' => $category['name']])
                : __('You marked it :priority priority.', ['priority' => $inputs['product']['priority']]);
        }

        return ['verdict' => $verdict, 'reasons' => array_slice($reasons, 0, 3)];
    }
}
