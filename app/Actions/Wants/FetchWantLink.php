<?php

namespace App\Actions\Wants;

use App\Models\User;
use App\Services\LinkPreview\LinkPreview;
use App\Services\LinkPreview\LinkPreviewResult;
use Illuminate\Support\Facades\RateLimiter;

class FetchWantLink
{
    /** Link reads per user per minute: far above normal use (audit finding 2, D-054). */
    public const PER_MINUTE = 20;

    public function __construct(private LinkPreview $preview) {}

    /**
     * Read a product link for the want form. Null when the user has read too many
     * links this minute: the page then asks them to fill in the details.
     */
    public function handle(User $user, string $url): ?LinkPreviewResult
    {
        $key = 'link-fetch:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return null;
        }

        RateLimiter::hit($key, 60);

        return $this->preview->fetch($url);
    }
}
