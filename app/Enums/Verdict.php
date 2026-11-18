<?php

namespace App\Enums;

/**
 * want_analyses.verdict.
 */
enum Verdict: string
{
    case BuyNow = 'buy_now';
    case Wait = 'wait';
    case Skip = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::BuyNow => 'Buy now',
            self::Wait => 'Wait',
            self::Skip => 'Skip',
        };
    }
}
