<?php

namespace App\Enums;

enum TurnMessageKind: string
{
    case Prompt = 'prompt';
    case Steer = 'steer';
}
