<?php

use App\Channels\Email\HealthCheck;
use App\Services\HealthCheck\HealthStatus;

beforeEach(function (): void {
    config()->set('yak.channels.email.inbound_address', 'yak@example.test');
});

it('fails when no real mailer is configured', function (string $mailer): void {
    config()->set('mail.default', $mailer);

    expect((new HealthCheck)->run()->status)->toBe(HealthStatus::Error);
})->with(['log', 'array']);

it('passes with a real mailer and names the inbound address', function (): void {
    config()->set('mail.default', 'postmark');

    $result = (new HealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toContain('yak@example.test');
});
