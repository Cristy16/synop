<?php

use Livewire\Component;

/**
 * Today → Vitamins. Placeholder until Phase 5 (Personal Care) fills it in; keep the x-today.section wrapper.
 */
new class extends Component {
    //
}; ?>

<x-today.section :title="__('Vitamins')" icon="beaker" :empty="__('Today\'s vitamin doses will show here.')" />
