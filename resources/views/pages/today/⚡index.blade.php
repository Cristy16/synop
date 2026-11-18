<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The home screen (PRD §2.2). It only lays out the sections; each section is
 * its own component in resources/views/components/today, filled by its module.
 */
new #[Title('Today')] class extends Component {
    //
}; ?>

<div class="mx-auto w-full max-w-5xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Today') }}</flux:heading>
        <flux:text class="mt-1" data-test="today-date">
            {{ now(Auth::user()->timezone)->translatedFormat('l, F j') }}
        </flux:text>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <livewire:today.tasks />
        <livewire:today.personal-care />
        <livewire:today.vitamins />
        <livewire:today.classes />
        <livewire:today.payments-due />
    </div>
</div>
