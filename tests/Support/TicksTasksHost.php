<?php

namespace Tests\Support;

use App\Livewire\Concerns\TicksTasks;
use Livewire\Component;

/**
 * TicksTasks is used by Livewire single-file components in resources/views, which
 * PHPStan doesn't read. This host lets PHPStan analyse the trait (see phpstan.neon).
 */
class TicksTasksHost extends Component
{
    use TicksTasks;

    public function render(): string
    {
        return '<div></div>';
    }
}
