<?php

namespace App\Services\LinkPreview;

use App\Support\Money;
use InvalidArgumentException;

/**
 * The "paste the product description" fallback (D-049): the first line as a
 * title, and the first peso amount ("₱1,299.00", "PHP 1299") as the price.
 */
class DescriptionParser
{
    /**
     * @return array{title: string|null, price: Money|null}
     */
    public static function parse(string $text): array
    {
        $title = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $title = mb_substr(trim($line), 0, 255);
                break;
            }
        }

        $price = null;

        if (preg_match('/(?:₱|\bPHP\b|\bPhp\b)\s?([0-9][0-9,]*(?:\.[0-9]{1,2})?)/u', $text, $match) === 1) {
            try {
                $price = Money::parse($match[1]);
            } catch (InvalidArgumentException) {
                $price = null;
            }
        }

        return ['title' => $title, 'price' => $price];
    }
}
