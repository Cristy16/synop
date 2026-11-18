<?php

use Livewire\Component;

/**
 * Today → Payments Due. Placeholder until Phase 3 (Finance) fills it in; keep the x-today.section wrapper.
 */
new class extends Component {
    //
}; ?>

<x-today.section :title="__('Payments Due')" icon="banknotes" :empty="__('Debts due soon will show here.')" />
