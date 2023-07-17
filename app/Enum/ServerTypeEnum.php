<?php

namespace App\Enum;

enum ServerTypeEnum: string
{
    case ORIGIN = 'origin';
    case EDGE = 'edge';
}
