<?php

namespace App\Enums;

enum StackMessageKind: string
{
    case Result = 'result';
    case Failure = 'failure';
    case QuestionAnswered = 'question_answered';
    case Note = 'note';
}
