<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Produces a 1-2 sentence summary of a long task description for the
 * condensed thread view. Failures are non-fatal — the UI falls back to
 * a line clamp.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class TaskDescriptionSummarizer implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-description-summary');
    }
}
