<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Test-only stand-in for a task source (activity, debt, product), so linking
 * can be tested before those modules exist. Uses the owned_things table.
 */
class SourceThing extends OwnedThing
{
    protected $table = 'owned_things';

    public static function register(): void
    {
        static::createTable();

        Relation::morphMap(['source_thing' => self::class]);
    }
}
