<?php

namespace Tests\Support;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;

/**
 * Stands in for Gemini in tests: no network. Records what it was sent.
 */
class FakeAiClient implements AiClient
{
    /** @var list<array<string, mixed>> */
    public array $verdictCalls = [];

    public int $labelCalls = 0;

    /**
     * @param  array{verdict: string, reasons: list<string>}|null  $verdict  null = fail
     */
    public function __construct(
        public bool $configured = true,
        public ?array $verdict = ['verdict' => 'buy_now', 'reasons' => ['It fits your budget.', 'You marked it high priority.']],
        public ?string $label = 'Aqua, Niacinamide, Glycerin',
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function wantVerdict(array $inputs): array
    {
        $this->verdictCalls[] = $inputs;

        return $this->verdict ?? throw new AiUnavailable('Fake failure.');
    }

    public function readIngredientLabel(string $imageBytes, string $mimeType): string
    {
        $this->labelCalls++;

        return $this->label ?? throw new AiUnavailable('Fake failure.');
    }
}
