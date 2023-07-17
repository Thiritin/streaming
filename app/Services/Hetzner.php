<?php

namespace App\Services;

class Hetzner
{
    public static function client(): \LKDev\HetznerCloud\HetznerAPIClient
    {
        return new \LKDev\HetznerCloud\HetznerAPIClient(config('services.hetzner.token'));
    }
}
