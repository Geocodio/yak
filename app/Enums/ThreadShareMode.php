<?php

namespace App\Enums;

enum ThreadShareMode: string
{
    case OnlyMe = 'only_me';
    case People = 'people';
    case Everyone = 'everyone';
}
