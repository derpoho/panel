<?php

namespace App\Enums;

enum MachineEnvironment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';
}
