<?php

namespace App\Enums;

/**
 * How one agent run ended, independent of what the task's status became
 * afterwards. `NoChanges` is a clean run that produced no commits (the
 * agent answered in prose); `Exception` is a failure outside the agent
 * itself (sandbox, git, push, PR creation).
 */
enum TaskRunOutcome: string
{
    case Success = 'success';
    case Error = 'error';
    case Clarification = 'clarification';
    case NoChanges = 'no_changes';
    case Exception = 'exception';
}
