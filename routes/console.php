<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Morning summary push (D-053): each user's chosen local time, checked every five minutes.
Schedule::command('synop:morning-summaries')->everyFiveMinutes()->withoutOverlapping();
