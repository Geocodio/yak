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
 * Estimates, for each risk-profile area, how likely a request is to touch it.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class RiskAreaGuessAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-risk-area-guess');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'areas' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required()
                    ->description('Exact area name from the list.'),
                'likelihood' => $schema->integer()->required()
                    ->description('Likelihood (0-100) that the request touches this area.'),
            ]))->required(),
        ];
    }
}
