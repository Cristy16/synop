<?php

namespace App\Actions\Notifications;

use App\Models\User;

class RemovePushSubscription
{
    /**
     * Stop pushes to this browser. Only the user's own subscription can be removed.
     */
    public function handle(User $user, string $endpoint): void
    {
        $user->deletePushSubscription($endpoint);
    }
}
