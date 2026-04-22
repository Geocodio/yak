<?php

namespace App\Services\HealthCheck;

use Illuminate\Support\Facades\Cache;

class WebhookSignaturesCheck implements HealthCheck
{
    /**
     * Channel keys whose webhook controllers call VerifiesWebhookSignature.
     * Each entry is the lowercase channel name (matches the key the trait
     * writes under) paired with a display label.
     *
     * @var array<string, string>
     */
    private const CHANNELS = [
        'slack' => 'Slack',
        'linear' => 'Linear',
        'sentry' => 'Sentry',
        'github' => 'GitHub',
    ];

    public function id(): string
    {
        return 'webhook-signatures';
    }

    public function name(): string
    {
        return 'Webhook Signatures';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $failures = [];

        foreach (self::CHANNELS as $key => $label) {
            $count = (int) Cache::get("webhook-signature-failures:{$key}", 0);
            if ($count > 0) {
                $failures[] = "{$label} ({$count})";
            }
        }

        if (empty($failures)) {
            return HealthResult::ok('No rejected webhooks');
        }

        return HealthResult::error(
            'Rejected webhooks — check signing secrets: ' . implode(', ', $failures),
        );
    }
}
