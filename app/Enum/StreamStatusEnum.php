<?php

namespace App\Enum;

enum StreamStatusEnum: string
{
    case STARTING_SOON = 'starting_soon';
    case PROVISIONING = 'provisioning';
    case ONLINE = 'online';
    case OFFLINE = 'offline';
    case TECHNICAL_ISSUE = 'technical_issue';
}
