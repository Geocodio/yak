<?php

namespace App\Enums;

enum StackThreadMessageKind: string
{
    case Message = 'message';
    case Question = 'question';
    case Answer = 'answer';
    case Result = 'result';
    case Failure = 'failure';
}
