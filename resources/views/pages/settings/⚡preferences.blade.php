<?php

use App\Actions\Settings\UpdatePreferences;
use App\Enums\Currency;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferences')] class extends Component {
    public string $timezone = '';
    public string $currency = '';
    public string $safety_buffer = '';
    public string $payments_due_days = '';
    public string $paydays = '';

    /**
     * Fill the form with the user's current preferences.
     */
    public function mount(): void
    {
        $user = Auth::user();

        $this->timezone = $user->timezone;
        $this->currency = $user->currency->value;
        $this->safety_buffer = $user->safety_buffer_cents->toDecimalString();
        $this->payments_due_days = (string) $user->payments_due_days;
        $this->paydays = implode(', ', $user->paydays ?? []);
    }

    /**
     * Save the preferences through the UpdatePreferences Action.
     */
    public function updatePreferences(UpdatePreferences $updatePreferences): void
    {
        $updatePreferences->handle(Auth::user(), [
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'safety_buffer' => $this->safety_buffer,
            'payments_due_days' => $this->payments_due_days,
            'paydays' => $this->paydays,
        ]);

        $this->safety_buffer = Auth::user()->safety_buffer_cents->toDecimalString();

        Flux::toast(variant: 'success', text: __('Preferences saved.'));
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Preferences') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Preferences')" :subheading="__('Timezone, currency and money settings')">
        <form wire:submit="updatePreferences" class="my-6 w-full space-y-6">
            <flux:select wire:model="timezone" :label="__('Timezone')" :description="__('Your dates and today\'s list follow this timezone.')">
                @foreach ($this->timezones as $tz)
                    <flux:select.option :value="$tz">{{ $tz }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:select wire:model="currency" :label="__('Currency')">
                    @foreach (Currency::cases() as $option)
                        <flux:select.option :value="$option->value">
                            {{ $option->value }} – {{ $option->label() }} ({{ $option->symbol() }})
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:text class="mt-2 text-sm">
                    {{ __('Changing currency only changes the symbol. Amounts are not converted (₱500 → $500).') }}
                </flux:text>
            </div>

            <flux:input
                wire:model="safety_buffer"
                :label="__('Safety buffer')"
                :description="__('Money you always keep aside. It is left out of what is safe to spend.')"
                inputmode="decimal"
                placeholder="0.00"
                data-test="safety-buffer-input"
            />

            <flux:input
                wire:model="payments_due_days"
                :label="__('Payments due window (days)')"
                :description="__('Today shows debts due within this many days, plus any overdue.')"
                type="number"
                min="{{ UpdatePreferences::MIN_PAYMENTS_DUE_DAYS }}"
                max="{{ UpdatePreferences::MAX_PAYMENTS_DUE_DAYS }}"
                inputmode="numeric"
            />

            <flux:input
                wire:model="paydays"
                :label="__('Paydays')"
                :description="__('Days of the month you get paid, e.g. 15, 30. Safe-to-spend looks ahead to the next one. Empty = end of the month.')"
                placeholder="15, 30"
                data-test="paydays-input"
            />

            <flux:button variant="primary" type="submit" data-test="update-preferences-button">
                {{ __('Save') }}
            </flux:button>
        </form>
    </x-pages::settings.layout>
</section>
