<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Classifies a task description as `fix` or `research`. Used by input
 * drivers when the user didn't explicitly prefix their message with
 * `research:`. The prompt asks for a single bare word — the calling
 * service handles normalisation and fallback.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class TaskIntentClassifier implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-task-intent');
    }
}
