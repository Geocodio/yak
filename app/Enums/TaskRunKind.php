<?php

namespace App\Enums;

/**
 * Which kind of agent invocation a task_runs row records. A single task
 * can have several: an initial run, a CI retry, a clarification reply.
 * Follow-ups are their own task rows, so a follow-up run is the initial
 * run of that child task, recorded as FollowUp.
 */
enum TaskRunKind: string
{
    case Initial = 'initial';
    case Retry = 'retry';
    case FollowUp = 'follow_up';
    case Clarification = 'clarification';
    case Research = 'research';
    case Review = 'review';
    case Setup = 'setup';
}
