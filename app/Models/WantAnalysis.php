<?php

namespace App\Models;

use App\Enums\AnalysisEngine;
use App\Enums\Verdict;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached "should I buy it?" verdict for a want (DATABASE §3.7, D-051).
 *
 * @property int $id
 * @property int $user_id
 * @property int $want_id
 * @property Verdict $verdict
 * @property list<string> $reasons
 * @property AnalysisEngine $engine
 * @property array<string, mixed> $inputs
 * @property string $inputs_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class WantAnalysis extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => Verdict::class,
            'reasons' => 'array',
            'engine' => AnalysisEngine::class,
            'inputs' => 'array',
            'want_id' => 'integer',
        ];
    }
}
