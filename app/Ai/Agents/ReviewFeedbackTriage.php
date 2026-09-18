<?php

namespace App\Ai\Agents;

use App\Facades\Prompts;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Decides whether a submitted GitHub review on one of Yak's PRs needs a
 * follow-up run (`act`) or is praise and acknowledgement only (`none`).
 * The prompt asks for a single bare word; ReviewFeedbackTriageDecision
 * handles normalisation and the fallback.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class ReviewFeedbackTriage implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return Prompts::render('agents-review-feedback-triage');
    }
}
