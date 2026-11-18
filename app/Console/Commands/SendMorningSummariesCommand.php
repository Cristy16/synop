<?php

namespace App\Console\Commands;

use App\Actions\Notifications\SendMorningSummaries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('synop:morning-summaries')]
#[Description('Send the morning summary push to users whose chosen time has just passed')]
class SendMorningSummariesCommand extends Command
{
    public function handle(SendMorningSummaries $send): int
    {
        if (! SendMorningSummaries::isConfigured()) {
            $this->components->warn('Web push is not set up: add VAPID keys to .env (php artisan webpush:vapid).');

            return self::SUCCESS;
        }

        $this->components->info('Sent '.$send->handle().' morning summaries.');

        return self::SUCCESS;
    }
}
