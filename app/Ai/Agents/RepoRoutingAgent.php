<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Picks the single best-matching repository from a natural language
 * task description. Expected to return only a repository slug or "UNKNOWN".
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class RepoRoutingAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-repo-routing');
    }
}
