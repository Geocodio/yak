<?php

namespace App\Services\HealthCheck;

use Illuminate\Support\Facades\Cache;

class WebhookSignaturesCheck implements HealthCheck
{
    /** @var list<string> */
    private const CONTROLLERS = [
        'SlackWebhookController',
        'LinearWebhookController',
        'SentryWebhookController',
        'GitHubWebhookController',
        'GitHubCIWebhookController',
        'DroneCIWebhookController',
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

        foreach (self::CONTROLLERS as $controller) {
            $count = (int) Cache::get("webhook-signature-failures:{$controller}", 0);
            if ($count > 0) {
                $name = str_replace('WebhookController', '', $controller);
                $failures[] = "{$name} ({$count})";
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
