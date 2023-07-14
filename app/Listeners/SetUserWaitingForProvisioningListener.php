<?php

namespace App\Listeners;

use App\Events\UserWaitingForProvisioningEvent;

class SetUserWaitingForProvisioningListener
{
    public function __construct()
    {
    }

    public function handle(UserWaitingForProvisioningEvent $event): void
    {
        $event->user->update(['is_provisioning' => true]);
    }
}
