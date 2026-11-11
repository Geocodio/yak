<?php

namespace App\Enums;

enum FileScope: string
{
    case Repository = 'repo';
    case Sandbox = 'sandbox';
}
