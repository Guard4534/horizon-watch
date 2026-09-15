<?php

namespace App\Enums;

enum EnvironmentColor: string
{
    case Prod = 'prod';
    case Preprod = 'preprod';
    case Staging = 'staging';
    case Develop = 'develop';
    case Demo = 'demo';
    case Worker = 'worker';
    case Testing = 'testing';
}
