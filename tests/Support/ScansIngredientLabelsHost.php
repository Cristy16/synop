<?php

namespace Tests\Support;

use App\Livewire\Concerns\ScansIngredientLabels;
use Livewire\Component;

/**
 * ScansIngredientLabels is used by Livewire single-file components in resources/views,
 * which PHPStan doesn't analyse. This host lets it check the trait.
 */
class ScansIngredientLabelsHost extends Component
{
    use ScansIngredientLabels;

    public string $ingredients_text = '';

    public function render(): string
    {
        return '<div></div>';
    }
}
