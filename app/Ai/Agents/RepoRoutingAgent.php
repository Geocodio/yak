<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Estimates, for each plausible repository, the probability that a natural
 * language task description belongs to it.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class RepoRoutingAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-repo-routing');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'candidates' => $schema->array()->items($schema->object([
                'slug' => $schema->string()->required()
                    ->description('Exact repository slug from the list.'),
                'probability' => $schema->integer()->required()
                    ->description('Probability (0-100) that the task belongs to this repository.'),
            ]))->required(),
        ];
    }
}
