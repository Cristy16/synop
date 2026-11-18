<?php

namespace App\Enums;

/**
 * wants.product_type. Skincare and makeup enable the ingredient check (Phase 7).
 */
enum WantProductType: string
{
    case Skincare = 'skincare';
    case Makeup = 'makeup';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Skincare => 'Skincare',
            self::Makeup => 'Makeup',
            self::Other => 'Something else',
        };
    }
}
