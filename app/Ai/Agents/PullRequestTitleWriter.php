<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes a concise, commit-style PR title from the task request and the
 * result summary. Failures are non-fatal — the PR falls back to a
 * truncated task description.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class PullRequestTitleWriter implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-pr-title');
    }
}
