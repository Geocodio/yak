<?php

use App\Models\YakTask;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config()->set('yak.clarification_ttl_days', 7);
});

test('a question is reminded after three days and expires after seven at the same time of day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 14:30:00'));

    $deadlines = YakTask::clarificationDeadlines();

    expect($deadlines['clarification_reminder_at']->toDateTimeString())->toBe('2026-10-05 14:30:00')
        ->and($deadlines['clarification_expires_at']->toDateTimeString())->toBe('2026-10-09 14:30:00');
});

test('weekends are not skipped', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00'));

    $deadlines = YakTask::clarificationDeadlines();

    expect($deadlines['clarification_reminder_at']->toDateTimeString())->toBe('2026-10-06 10:00:00')
        ->and($deadlines['clarification_expires_at']->toDateTimeString())->toBe('2026-10-10 10:00:00');
});
