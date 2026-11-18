<?php

namespace App\Services\Ai;

/**
 * The AI provider, behind an interface so a paid provider later is one binding
 * change (D-026). Implementations never compute money: they only explain the
 * figures PHP already worked out (D-027). Both methods throw AiUnavailable
 * instead of failing in any other way.
 */
interface AiClient
{
    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $inputs  product info, PHP-computed figures, ingredient flags (no names, contacts or accounts)
     * @return array{verdict: string, reasons: list<string>} as sent back, validated by the caller
     *
     * @throws AiUnavailable
     */
    public function wantVerdict(array $inputs): array;

    /**
     * Read the ingredient list from a label photo. The image is sent once and never stored.
     *
     * @throws AiUnavailable
     */
    public function readIngredientLabel(string $imageBytes, string $mimeType): string;
}
