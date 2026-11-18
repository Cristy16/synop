<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The morning push (D-053). Tapping it opens Today.
 */
class MorningSummary extends Notification
{
    public function __construct(public string $title, public string $body) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/icon-192.png')
            ->tag('morning-summary')
            ->data(['url' => '/today'])
            ->options(['TTL' => 4 * 3600, 'urgency' => 'normal']);
    }
}
