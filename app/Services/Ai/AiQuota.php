<?php

namespace App\Services\Ai;

use App\Models\AiRequest;
use App\Models\User;

/**
 * Answers "how many AI calls are left today?" — 10 real calls per user per local
 * day, both kinds together (D-028). Read-only; the Actions log each call.
 */
class AiQuota
{
    public const DAILY_LIMIT = 10;

    public function usedToday(User $user): int
    {
        return AiRequest::forUser($user)->whereDate('requested_on', now($user->timezone)->toDateString())->count();
    }

    public function remaining(User $user): int
    {
        return max(0, self::DAILY_LIMIT - $this->usedToday($user));
    }
}
