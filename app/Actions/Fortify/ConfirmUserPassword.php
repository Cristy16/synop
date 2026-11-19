<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ConfirmUserPassword
{
    /** Wrong tries per user per minute before waiting (audit finding 4, D-054). */
    public const PER_MINUTE = 5;

    /**
     * Fortify's "confirm your password" check, with a limit on wrong guesses so a
     * stolen session can't keep guessing to reach Security settings.
     *
     * @throws ValidationException when there were too many wrong tries
     */
    public function handle(User $user, ?string $password): bool
    {
        $key = 'confirm-password:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw ValidationException::withMessages([
                'password' => __('Too many tries. Wait :seconds seconds and try again.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if ($password !== null && Hash::check($password, $user->password)) {
            RateLimiter::clear($key);

            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
