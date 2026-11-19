<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Notifications\MorningSummary as MorningSummaryNotification;
use App\Services\MorningSummary;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class SendMorningSummaries
{
    /** Up to this long after the chosen time; a scheduler that was down doesn't send at night. */
    public const WINDOW_MINUTES = 180;

    public function __construct(private MorningSummary $summary) {}

    public static function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /**
     * Send today's summary to every user whose morning_summary_time has passed in
     * their own timezone, once per local day, to each of their devices (D-053).
     * Run every few minutes by the scheduler. Returns how many users were sent one.
     */
    public function handle(): int
    {
        if (! self::isConfigured()) {
            return 0;
        }

        $sent = 0;

        User::query()
            ->whereNotNull('morning_summary_time')
            ->whereHas('pushSubscriptions')
            ->orderBy('id')
            ->each(function (User $user) use (&$sent) {
                if ($this->isDue($user)) {
                    $this->sendTo($user);
                    $sent++;
                }
            });

        return $sent;
    }

    /** "Send a test" pushes per user per 10 minutes (audit finding 6, D-054). */
    public const TESTS_PER_10_MINUTES = 3;

    /**
     * Send it right now (the "Send a test" button), without touching the once-a-day mark.
     *
     * @throws ValidationException after too many tests
     */
    public function sendNow(User $user): void
    {
        $key = 'test-push:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::TESTS_PER_10_MINUTES)) {
            throw ValidationException::withMessages([
                'push' => __('That\'s enough tests for now. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }

        RateLimiter::hit($key, 600);
        $this->deliver($user);
    }

    private function isDue(User $user): bool
    {
        $now = now($user->timezone)->toImmutable();
        $at = $now->setTimeFromTimeString((string) $user->morning_summary_time);
        $today = $now->toDateString();

        return $user->morning_summary_sent_on?->toDateString() !== $today
            && $now->greaterThanOrEqualTo($at)
            && $now->lessThan($at->addMinutes(self::WINDOW_MINUTES));
    }

    private function sendTo(User $user): void
    {
        // Marked first: a failure isn't retried every five minutes all morning.
        $user->forceFill(['morning_summary_sent_on' => now($user->timezone)->toDateString()])->save();

        try {
            $this->deliver($user);
        } catch (Throwable $e) {
            Log::warning('Morning summary failed.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    private function deliver(User $user): void
    {
        // Today's data is read as this user: user-owned models fail closed with no one logged in.
        $previous = Auth::user();
        Auth::setUser($user);

        try {
            $summary = $this->summary->for($user);
        } finally {
            $previous === null ? Auth::forgetUser() : Auth::setUser($previous);
        }

        $user->notify(new MorningSummaryNotification($summary['title'], $summary['body']));
    }
}
