<?php

namespace App\Enums;

enum TaskMode: string
{
    case Fix = 'fix';
    case Research = 'research';
    case Setup = 'setup';
}
