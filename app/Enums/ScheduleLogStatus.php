<?php

namespace App\Enums;

/**
 * What the user did with one occurrence (schedule_logs.status).
 */
enum ScheduleLogStatus: string
{
    case Done = 'done';
    case Skipped = 'skipped';
}
