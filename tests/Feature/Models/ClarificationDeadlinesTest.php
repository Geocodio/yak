<?php

use App\Models\YakTask;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config()->set('yak.clarification_ttl_days', 3);
});

test('a Friday question is reminded on Monday and expires on Wednesday at the same time of day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 14:30:00'));

    $deadlines = YakTask::clarificationDeadlines();

    expect($deadlines['clarification_reminder_at']->toDateTimeString())->toBe('2026-10-05 14:30:00')
        ->and($deadlines['clarification_expires_at']->toDateTimeString())->toBe('2026-10-07 14:30:00');
});

test('a Saturday question counts its working days from Monday', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00'));

    $deadlines = YakTask::clarificationDeadlines();

    expect($deadlines['clarification_reminder_at']->toDateTimeString())->toBe('2026-10-05 10:00:00')
        ->and($deadlines['clarification_expires_at']->toDateTimeString())->toBe('2026-10-07 10:00:00');
});
