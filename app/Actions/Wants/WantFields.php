<?php

namespace App\Actions\Wants;

use App\Models\Want;

class WantFields
{
    /**
     * @param  array<string, mixed>  $values  from WantInput::validate()
     */
    public static function fill(Want $want, array $values): void
    {
        foreach ($values as $key => $value) {
            $want->setAttribute($key, $value);
        }
    }
}
