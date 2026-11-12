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
 * Reads a pull request diff and reports which repository concerns it touches and what kind of change it is.
 */
#[Provider('anthropic')]
#[Model('claude-sonnet-5-5')]
class ConcernClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-concern-classifier');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'touched_concerns' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required()
                    ->description('Exact concern name from the list.'),
                'evidence' => $schema->string()->required()
                    ->description('The file and the line or behavior that touches the concern, one sentence.'),
            ]))->required(),
            'change_kind' => $schema->string()
                ->enum(['no_runtime_change', 'presentation_or_internal', 'behavior_change'])
                ->required(),
            'is_uncertain' => $schema->boolean()->required(),
        ];
    }
}
