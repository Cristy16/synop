<?php

use App\Actions\Notifications\RemovePushSubscription;
use App\Actions\Notifications\SavePushSubscription;
use App\Actions\Notifications\SendMorningSummaries;
use App\Actions\Notifications\UpdateNotificationSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    public bool $morning_summary_enabled = false;
    public string $morning_summary_time = '07:00';

    public function mount(): void
    {
        $user = Auth::user();
        $this->morning_summary_enabled = $user->morning_summary_time !== null;
        $this->morning_summary_time = $user->morning_summary_time !== null ? substr($user->morning_summary_time, 0, 5) : '07:00';
    }

    #[Computed]
    public function devices(): int
    {
        return Auth::user()->pushSubscriptions()->count();
    }

    public function save(UpdateNotificationSettings $update): void
    {
        $this->resetValidation();
        $update->handle(Auth::user(), [
            'morning_summary_enabled' => $this->morning_summary_enabled,
            'morning_summary_time' => $this->morning_summary_time,
        ]);
        Flux::toast(__('Saved.'), variant: 'success');
    }

    /**
     * Called from the browser after it subscribed.
     *
     * @param  array<string, mixed>  $subscription
     */
    public function subscribe(array $subscription, SavePushSubscription $save): void
    {
        $this->resetValidation();
        $save->handle(Auth::user(), $subscription);
        unset($this->devices);
    }

    public function unsubscribe(string $endpoint, RemovePushSubscription $remove): void
    {
        $remove->handle(Auth::user(), $endpoint);
        unset($this->devices);
    }

    public function sendTest(SendMorningSummaries $send): void
    {
        $this->resetValidation();
        $send->sendNow(Auth::user());
        Flux::toast(__('Sent. It can take a few seconds.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notifications') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('A morning summary of your Today page')">
        <div class="my-6 w-full space-y-6">
            @if (! SendMorningSummaries::isConfigured())
                <flux:callout icon="information-circle" variant="warning" data-test="push-not-configured">
                    <flux:callout.text>{{ __('Push notifications aren\'t set up on this server yet (VAPID keys are missing).') }}</flux:callout.text>
                </flux:callout>
            @else
                {{-- This device: ask permission, subscribe, hand the subscription to the server. --}}
                <div
                    x-data="{
                        state: 'checking',
                        endpoint: null,
                        async init() {
                            if (! ('serviceWorker' in navigator) || ! ('PushManager' in window)) { this.state = 'unsupported'; return; }
                            if (Notification.permission === 'denied') { this.state = 'denied'; return; }
                            const reg = await navigator.serviceWorker.ready;
                            const sub = await reg.pushManager.getSubscription();
                            this.endpoint = sub?.endpoint ?? null;
                            this.state = sub ? 'on' : 'off';
                            if (sub) $wire.subscribe(sub.toJSON());
                        },
                        key() {
                            const b64 = @js(config('webpush.vapid.public_key'));
                            const raw = atob((b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
                            return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
                        },
                        async turnOn() {
                            if (await Notification.requestPermission() !== 'granted') { this.state = 'denied'; return; }
                            const reg = await navigator.serviceWorker.ready;
                            const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.key() });
                            await $wire.subscribe(sub.toJSON());
                            this.endpoint = sub.endpoint;
                            this.state = 'on';
                        },
                        async turnOff() {
                            const reg = await navigator.serviceWorker.ready;
                            const sub = await reg.pushManager.getSubscription();
                            if (sub) { await $wire.unsubscribe(sub.endpoint); await sub.unsubscribe(); }
                            this.endpoint = null;
                            this.state = 'off';
                        },
                    }"
                    class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                    data-test="this-device"
                >
                    <flux:heading size="sm">{{ __('This device') }}</flux:heading>
                    <flux:text x-show="state === 'checking'">{{ __('Checking…') }}</flux:text>
                    <flux:text x-show="state === 'unsupported'" x-cloak>{{ __('This browser can\'t receive notifications. On iPhone, add Synop to your home screen first (Share → Add to Home Screen), then open it from there.') }}</flux:text>
                    <flux:text x-show="state === 'denied'" x-cloak>{{ __('Notifications are blocked for this site. Allow them in your browser or phone settings, then come back.') }}</flux:text>
                    <div x-show="state === 'off'" x-cloak class="space-y-2">
                        <flux:text>{{ __('Notifications are off on this device.') }}</flux:text>
                        <flux:button variant="primary" icon="bell" x-on:click="turnOn()" data-test="push-on">{{ __('Turn on notifications') }}</flux:button>
                    </div>
                    <div x-show="state === 'on'" x-cloak class="flex flex-wrap items-center gap-2">
                        <flux:badge color="green" icon="check">{{ __('On for this device') }}</flux:badge>
                        <flux:button size="sm" wire:click="sendTest" data-test="push-test">{{ __('Send a test') }}</flux:button>
                        <flux:button size="sm" variant="ghost" x-on:click="turnOff()" data-test="push-off">{{ __('Turn off here') }}</flux:button>
                    </div>
                    <flux:error name="push" />
                    <flux:text class="text-xs" data-test="device-count">
                        {{ trans_choice('Sends to :count device.|Sends to :count devices.', $this->devices) }}
                    </flux:text>
                </div>
            @endif

            <form wire:submit="save" class="space-y-4">
                <flux:switch wire:model.live="morning_summary_enabled" :label="__('Morning summary')" :description="__('Today\'s tasks, classes, routine, vitamins and payments, as counts. No amounts or names.')" data-test="summary-switch" />

                @if ($morning_summary_enabled)
                    <flux:input wire:model="morning_summary_time" type="time" :label="__('Send at')" :description="__('Your local time (:tz).', ['tz' => Auth::user()->timezone])" class="max-w-40" data-test="summary-time" />
                @endif

                <flux:button variant="primary" type="submit" data-test="save-notifications">{{ __('Save') }}</flux:button>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
