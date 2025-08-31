<?php

namespace App\Enum;

enum ServerStatusEnum: string
{
    // provisioning, active, deprovisioning, deleted, error
    case PROVISIONING = 'provisioning';
    case ACTIVE = 'active';
    case DEPROVISIONING = 'deprovisioning';
    case DELETED = 'deleted';
    case ERROR = 'error';

}
