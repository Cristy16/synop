<?php

namespace App\Enums;

/**
 * products.type.
 */
enum ProductType: string
{
    case Skincare = 'skincare';
    case Makeup = 'makeup';
    case Vitamin = 'vitamin';

    public function label(): string
    {
        return match ($this) {
            self::Skincare => 'Skincare',
            self::Makeup => 'Makeup',
            self::Vitamin => 'Vitamins',
        };
    }
}
