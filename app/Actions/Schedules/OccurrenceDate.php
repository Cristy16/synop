<?php

namespace App\Actions\Schedules;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Checks the date given to MarkOccurrence / UnmarkOccurrence.
 */
class OccurrenceDate
{
    /**
     * @throws ValidationException
     */
    public static function validate(string $date): string
    {
        return Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate()['date'];
    }
}
