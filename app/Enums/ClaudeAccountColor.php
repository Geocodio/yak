<?php

namespace App\Enums;

/** Console-ui palette names an account can be tinted with. */
enum ClaudeAccountColor: string
{
    case Accent = 'accent';
    case Brand = 'brand';
    case Info = 'info';
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Note = 'note';
    case Idle = 'idle';
}
