{{--
    "Add to home screen" hint (D-052). Android/Chrome: an Install button from the
    browser's install prompt. iPhone Safari: the Share → Add to Home Screen steps.
    Hidden once installed or dismissed (remembered on this device).
--}}
<div
    x-data="{
        show: false,
        ios: false,
        prompt: null,
        init() {
            const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            let dismissed = false;
            try { dismissed = localStorage.getItem('synop.installHint') === 'dismissed'; } catch (e) {}
            if (standalone || dismissed) return;

            this.ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && ! /crios|fxios/i.test(navigator.userAgent);
            this.show = this.ios;
            window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); this.prompt = e; this.show = true; });
            window.addEventListener('appinstalled', () => { this.show = false; });
        },
        async install() {
            if (! this.prompt) return;
            this.prompt.prompt();
            await this.prompt.userChoice;
            this.prompt = null;
            this.show = false;
        },
        dismiss() {
            this.show = false;
            try { localStorage.setItem('synop.installHint', 'dismissed'); } catch (e) {}
        },
    }"
    x-show="show"
    x-cloak
    class="mb-4 flex items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900"
    data-test="install-hint"
>
    <img src="/icons/icon-192.png" alt="" class="size-10 shrink-0 rounded-lg">
    <div class="min-w-0 flex-1">
        <div class="font-medium">{{ __('Add Synop to your home screen') }}</div>
        <p x-show="ios" class="mt-1 text-zinc-600 dark:text-zinc-300">
            {{ __('In Safari, tap Share') }}
            <svg class="inline size-4 align-text-bottom" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>
            {{ __('then "Add to Home Screen". Needed for morning reminders on iPhone.') }}
        </p>
        <p x-show="! ios" class="mt-1 text-zinc-600 dark:text-zinc-300">{{ __('Opens like an app, full screen, one tap away.') }}</p>
        <div x-show="! ios" class="mt-2">
            <flux:button size="sm" variant="primary" x-on:click="install()">{{ __('Install') }}</flux:button>
        </div>
    </div>
    <button type="button" x-on:click="dismiss()" class="-m-1 flex size-11 shrink-0 items-center justify-center rounded-lg text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200" aria-label="{{ __('Dismiss') }}">
        <flux:icon name="x-mark" variant="mini" />
    </button>
</div>
