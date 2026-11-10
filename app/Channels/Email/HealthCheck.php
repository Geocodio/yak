<?php

namespace App\Channels\Email;

use App\Services\HealthCheck\ChannelCheck;
use App\Services\HealthCheck\HealthResult;

/**
 * The email channel needs a real outbound mailer: without one, confirmation
 * links and acknowledgments are written to the log and never arrive.
 */
class HealthCheck extends ChannelCheck
{
    public function id(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'Email';
    }

    public function run(): HealthResult
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            return HealthResult::error("Outbound mailer is \"{$mailer}\", so confirmation links and acknowledgments never arrive. Set MAIL_MAILER=postmark.");
        }

        return HealthResult::ok('Receiving at ' . config('yak.channels.email.inbound_address') . ", sending with {$mailer}");
    }
}
