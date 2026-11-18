<?php

namespace App\Actions\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateNotificationSettings
{
    /**
     * Turn the morning summary on (a local time, e.g. "07:00") or off (empty).
     * Changing the time to later today lets today's summary still come (D-053).
     *
     * @param  array<string, mixed>  $input  morning_summary_enabled (bool), morning_summary_time (H:i)
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'morning_summary_enabled' => ['required', 'boolean'],
            'morning_summary_time' => ['exclude_unless:morning_summary_enabled,true', 'required', 'date_format:H:i'],
        ])->validate();

        $time = $validated['morning_summary_enabled'] ? $validated['morning_summary_time'] : null;

        if ($time !== null && $time.':00' !== $user->morning_summary_time && $time > now($user->timezone)->format('H:i')) {
            $user->morning_summary_sent_on = null;
        }

        $user->morning_summary_time = $time;
        $user->save();
    }
}
