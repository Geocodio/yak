<?php

namespace App\Enums;

enum ThreadSessionKind: string
{
    case Main = 'main';
    case Fork = 'fork';
}
