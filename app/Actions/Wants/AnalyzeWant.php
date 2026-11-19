<?php

namespace App\Actions\Wants;

use App\Actions\Ai\ReserveAiCall;
use App\Enums\AiRequestKind;
use App\Enums\AnalysisEngine;
use App\Enums\Verdict;
use App\Models\User;
use App\Models\Want;
use App\Models\WantAnalysis;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\RulesVerdict;
use App\Services\WantAdvisorInputs;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class AnalyzeWant
{
    public function __construct(
        private WantAdvisorInputs $inputs,
        private AiClient $ai,
        private ReserveAiCall $reserve,
    ) {}

    /**
     * "Should I buy it?" (D-051): PHP computes every figure; the AI only picks a
     * verdict and explains it in 2–3 reasons. The same inputs reuse a cached AI
     * answer (no call, no quota). With no key, no calls left today, or a failed or
     * odd reply, a rules-based verdict is used instead. Never throws for AI problems.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Want $want): WantAnalysis
    {
        Gate::forUser($user)->authorize('update', $want);

        $inputs = $this->inputs->for($user, $want);
        $hash = WantAdvisorInputs::hash($inputs);

        $cached = WantAnalysis::forUser($user)
            ->where('want_id', $want->id)
            ->where('inputs_hash', $hash)
            ->where('engine', AnalysisEngine::Ai->value)
            ->latest('id')
            ->first();

        if ($cached !== null) {
            return $cached;
        }

        $reply = null;

        $call = $this->ai->isConfigured() ? $this->reserve->handle($user, AiRequestKind::WantVerdict) : null;

        if ($call !== null) {
            try {
                $reply = self::validated($this->ai->wantVerdict($inputs));
            } catch (AiUnavailable) {
                $reply = null;
            }

            ReserveAiCall::finish($call, $reply !== null);
        }

        $engine = $reply === null ? AnalysisEngine::Rules : AnalysisEngine::Ai;
        $reply ??= RulesVerdict::decide($inputs);

        // One rules answer per want and inputs: refreshed rather than piling up.
        $analysis = $engine === AnalysisEngine::Rules
            ? WantAnalysis::forUser($user)->where('want_id', $want->id)->where('inputs_hash', $hash)->where('engine', AnalysisEngine::Rules->value)->first() ?? new WantAnalysis
            : new WantAnalysis;

        $analysis->user_id = $user->id;
        $analysis->want_id = $want->id;
        $analysis->verdict = $reply['verdict'];
        $analysis->reasons = $reply['reasons'];
        $analysis->engine = $engine;
        $analysis->inputs = $inputs;
        $analysis->inputs_hash = $hash;
        $analysis->save();

        return $analysis;
    }

    /**
     * An unexpected verdict or reasons that aren't 2–3 short strings → null (rules fallback).
     *
     * @param  array{verdict: string, reasons: list<string>}  $reply
     * @return array{verdict: Verdict, reasons: list<string>}|null
     */
    public static function validated(array $reply): ?array
    {
        $verdict = Verdict::tryFrom($reply['verdict']);
        $reasons = array_values(array_filter(array_map(fn (string $r) => trim($r), $reply['reasons']), fn (string $r) => $r !== ''));

        if ($verdict === null || count($reasons) < 2 || count($reasons) > 3) {
            return null;
        }

        foreach ($reasons as $reason) {
            if (mb_strlen($reason) > 300) {
                return null;
            }
        }

        return ['verdict' => $verdict, 'reasons' => $reasons];
    }
}
