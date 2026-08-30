<?php

namespace App\Services\Cloud;

/**
 * What a driver can say about a machine without knowing anything about our schema.
 * `Gone` is the answer for a machine that never existed as well as for one that was
 * deleted: both mean there is nothing left to tear down.
 */
enum ServerState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Gone = 'gone';
}
