<?php

namespace App\Enum;

enum AutoscalerAction
{
    case NONE;
    case SCALE_UP;
    case SCALE_DOWN;
}
