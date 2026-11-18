<?php

namespace App\Actions\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\PushSubscription;

class SavePushSubscription
{
    /**
     * The server sends requests to the endpoint, so only real browser push services
     * are accepted (no internal or made-up addresses, D-053).
     */
    public const ALLOWED_HOSTS = [
        'fcm.googleapis.com',               // Chrome, Edge on Android, Samsung Internet
        'updates.push.services.mozilla.com', // Firefox
        'web.push.apple.com',               // Safari, iPhone home-screen apps
    ];

    /** Also allowed: any subdomain of these (e.g. wns2-par02p.notify.windows.com). */
    public const ALLOWED_SUFFIXES = ['.notify.windows.com', '.push.apple.com'];

    /**
     * Store this browser's push subscription for the user. An endpoint belongs to
     * one browser: if another account signed in there before, it moves to this one.
     *
     * @param  array<string, mixed>  $subscription  PushSubscription.toJSON(): endpoint, keys.p256dh, keys.auth
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $subscription, string $contentEncoding = 'aes128gcm'): void
    {
        $endpoint = $subscription['endpoint'] ?? null;
        $p256dh = $subscription['keys']['p256dh'] ?? null;
        $auth = $subscription['keys']['auth'] ?? null;

        if (! is_string($endpoint) || ! self::isAllowedEndpoint($endpoint)
            || ! is_string($p256dh) || preg_match('/^[A-Za-z0-9_-]{80,100}={0,2}$/', $p256dh) !== 1
            || ! is_string($auth) || preg_match('/^[A-Za-z0-9_-]{16,32}={0,2}$/', $auth) !== 1
            || ! in_array($contentEncoding, ['aes128gcm', 'aesgcm'], true)) {
            throw ValidationException::withMessages(['push' => __('This browser gave an unexpected push address. Try another browser.')]);
        }

        DB::transaction(function () use ($user, $endpoint, $p256dh, $auth, $contentEncoding) {
            PushSubscription::query()
                ->where('endpoint', $endpoint)
                ->where(fn ($q) => $q->where('subscribable_type', '!=', $user->getMorphClass())->orWhere('subscribable_id', '!=', $user->getKey()))
                ->delete();

            $user->updatePushSubscription($endpoint, $p256dh, $auth, $contentEncoding);
        });
    }

    public static function isAllowedEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > PushSubscription::ENDPOINT_MAX_LENGTH) {
            return false;
        }

        $parts = parse_url($endpoint);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }

        foreach (self::ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && preg_match('/^[a-z0-9.-]+$/', $host) === 1) {
                return true;
            }
        }

        return false;
    }
}
