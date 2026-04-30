<?php

use App\Channels\Slack\InteractivityHealthCheck;
use App\Channels\Slack\InteractivityTracker;
use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\HealthStatus;

beforeEach(function () {
    InteractivityTracker::reset();
});

it('is a channel section check with slack-interactivity identity', function () {
    $check = new InteractivityHealthCheck;

    expect($check->id())->toBe('slack-interactivity');
    expect($check->name())->toBe('Slack Interactivity');
    expect($check->section())->toBe(HealthSection::Channels);
});

it('warns when no clarification buttons have been sent yet', function () {
    $result = (new InteractivityHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warn);
    expect($result->detail)->toContain('not exercised');
});

it('errors when clarifications were sent but no clicks were received', function () {
    InteractivityTracker::recordSent();
    InteractivityTracker::recordSent();

    $result = (new InteractivityHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('2 button-bearing clarification');
    expect($result->detail)->toContain('Interactivity');
    expect($result->detail)->toContain('/webhooks/slack/interactive');
    expect($result->action)->not->toBeNull();
    expect($result->action->url)->toBe('https://api.slack.com/apps');
});

it('returns Ok once an interactive payload has been received', function () {
    InteractivityTracker::recordSent();
    InteractivityTracker::recordSent();
    InteractivityTracker::recordReceived();

    $result = (new InteractivityHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('1 of 2');
});
