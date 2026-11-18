<?php

namespace Tests\Support;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only user-owned model, so BelongsToUser and MoneyCast can be tested
 * before any real user-owned table exists.
 */
class OwnedThing extends Model
{
    use BelongsToUser;

    protected $fillable = ['name', 'amount_cents'];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
        ];
    }

    public static function createTable(): void
    {
        Schema::create('owned_things', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->bigInteger('amount_cents')->nullable();
            $table->timestamps();
        });
    }
}
