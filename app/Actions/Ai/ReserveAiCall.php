<?php

namespace App\Actions\Ai;

use App\Enums\AiRequestKind;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\AiQuota;
use Illuminate\Support\Facades\Cache;

class ReserveAiCall
{
    public function __construct(private AiQuota $quota) {}

    /**
     * Take one of today's AI calls before making it, so calls sent at the same time
     * can't go past the daily limit (audit finding 5, D-054). The row starts as not
     * succeeded; the caller marks it once the AI answered. Null = none left today.
     */
    public function handle(User $user, AiRequestKind $kind): ?AiRequest
    {
        return Cache::lock('ai-quota:'.$user->id, 10)->block(5, function () use ($user, $kind) {
            if ($this->quota->remaining($user) === 0) {
                return null;
            }

            $request = new AiRequest;
            $request->user_id = $user->id;
            $request->kind = $kind;
            $request->setAttribute('requested_on', now($user->timezone)->toDateString());
            $request->succeeded = false;
            $request->save();

            return $request;
        });
    }

    public static function finish(AiRequest $request, bool $succeeded): void
    {
        $request->succeeded = $succeeded;
        $request->save();
    }
}
