<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes a short imperative title for a task from the person's note and
 * the forwarded email's subject. Failures are non-fatal: the caller falls
 * back to the cleaned subject.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class TaskTitleAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-task-title');
    }
}
