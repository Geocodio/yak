<?php

namespace App\Channels\Slack;

use App\Services\HealthCheck\ChannelCheck;
use App\Services\HealthCheck\HealthAction;
use App\Services\HealthCheck\HealthResult;

/**
 * Heuristic check for the Slack app's Interactivity & Shortcuts URL.
 *
 * Slack doesn't expose an API for an app to read its own request URL
 * configuration, so we infer it from runtime traffic. If we've sent
 * button-bearing clarification messages but no interactive payloads
 * have ever come back, the URL almost certainly isn't configured —
 * clicks are going nowhere. Tell the user where to fix it.
 */
class InteractivityHealthCheck extends ChannelCheck
{
    public function id(): string
    {
        return 'slack-interactivity';
    }

    public function name(): string
    {
        return 'Slack Interactivity';
    }

    public function run(): HealthResult
    {
        $sent = InteractivityTracker::sentCount();
        $received = InteractivityTracker::receivedCount();

        if ($sent === 0 && $received === 0) {
            return HealthResult::warn(
                'No clarification buttons sent yet — interactivity URL not exercised.',
            );
        }

        if ($received === 0) {
            $action = new HealthAction(
                label: 'Configure in Slack admin',
                url: 'https://api.slack.com/apps',
            );

            return HealthResult::error(
                "Sent {$sent} button-bearing clarification(s) but received 0 clicks. "
                . 'The Slack app probably has no Interactivity & Shortcuts request URL — '
                . 'set it to ' . url('/webhooks/slack/interactive') . '.',
                $action,
            );
        }

        return HealthResult::ok("Receiving clicks ({$received} of {$sent} clarification(s) answered).");
    }
}
