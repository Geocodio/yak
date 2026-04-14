<?php

namespace App\Ai\Agents;

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
        return <<<'INSTRUCTIONS'
You are a routing classifier. The user will give you a list of repositories (with descriptions) and a task description. Your job is to pick the single repository the task most likely belongs to.

Respond with ONLY the repository slug on a single line, with no other text, no explanation, and no formatting. Example response: acme/api

If you cannot confidently determine the correct repository based on the information provided, respond with ONLY the word: UNKNOWN
INSTRUCTIONS;
    }
}
