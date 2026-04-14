<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Generates personality-infused notification messages for task
 * lifecycle events (acknowledgment, progress, result, etc.).
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class PersonalityAgent implements Agent
{
    use Promptable;

    public function __construct(
        public readonly string $type,
        public readonly string $context,
    ) {}

    public function instructions(): Stringable|string
    {
        return Prompts::render('personality', [
            'type' => $this->type,
            'context' => $this->context,
        ]);
    }
}
