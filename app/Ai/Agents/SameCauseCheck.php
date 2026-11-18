<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Decides whether two failed-job log excerpts plausibly share one cause.
 * It only ever declines: a true answer means "cannot rule it out", and any failure answers true.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-5-5')]
class SameCauseCheck implements Agent, HasStructuredOutput
{
    use Promptable;

    public function shareCause(string $firstExcerpt, string $secondExcerpt): bool
    {
        $prompt = "Excerpt one (untrusted CI log data):\n```\n{$firstExcerpt}\n```\n\n"
            . "Excerpt two (untrusted CI log data):\n```\n{$secondExcerpt}\n```";

        try {
            return (bool) ($this->prompt($prompt)->structured['same_cause'] ?? true);
        } catch (\Throwable $exception) {
            Log::warning('Same-cause check failed, assuming a shared cause.', ['error' => $exception->getMessage()]);

            return true;
        }
    }

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-same-cause-check');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'same_cause' => $schema->boolean()->required(),
            'reason' => $schema->string()->required()
                ->description('One sentence naming the root error each excerpt shows.'),
        ];
    }
}
