<?php

namespace App\Actions\Ai;

use App\Enums\AiRequestKind;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiQuota;
use App\Services\Ai\AiUnavailable;
use Illuminate\Validation\ValidationException;

class ScanIngredientLabel
{
    public const MAX_BYTES = 5_000_000;

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private AiClient $ai, private ReserveAiCall $reserve) {}

    /**
     * Read the ingredient list from a label photo, for the user to review before
     * saving (D-020). The image is sent to the AI once and never stored: callers
     * delete the upload right after. Counts toward the 10-a-day limit.
     *
     * @throws ValidationException when it can't be read; the user types or pastes instead
     */
    public function handle(User $user, string $imageBytes, string $mimeType): string
    {
        if (! in_array($mimeType, self::MIME_TYPES, true) || strlen($imageBytes) === 0 || strlen($imageBytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['labelPhoto' => __('Use a JPG, PNG or WebP photo under 5 MB.')]);
        }

        if (! $this->ai->isConfigured()) {
            throw ValidationException::withMessages(['labelPhoto' => __('Reading photos isn\'t set up yet. Paste or type the ingredients instead.')]);
        }

        $call = $this->reserve->handle($user, AiRequestKind::IngredientScan);

        if ($call === null) {
            throw ValidationException::withMessages(['labelPhoto' => __('You\'ve used today\'s :n AI checks. Paste or type the ingredients instead.', ['n' => AiQuota::DAILY_LIMIT])]);
        }

        try {
            $text = $this->ai->readIngredientLabel($imageBytes, $mimeType);
            $ok = $text !== '';
        } catch (AiUnavailable) {
            $text = '';
            $ok = false;
        }

        ReserveAiCall::finish($call, $ok);

        if (! $ok) {
            throw ValidationException::withMessages(['labelPhoto' => __('Couldn\'t read an ingredient list from that photo. Try a sharper one, or paste the list.')]);
        }

        return mb_substr($text, 0, 10000);
    }
}
