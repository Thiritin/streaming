<?php

namespace App\Services;

use LKDev\HetznerCloud\HetznerAPIClient;

class Hetzner
{
    public static function client(): HetznerAPIClient
    {
        return new HetznerAPIClient(config('services.hetzner.token'));
    }
}
