<?php

namespace App\Enums;

enum ThreadKind: string
{
    case Root = 'root';
    case Implement = 'implement';
    case Mockup = 'mockup';
    case Research = 'research';
    case General = 'general';
}
