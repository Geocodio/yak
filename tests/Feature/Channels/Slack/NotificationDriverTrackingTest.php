<?php

use App\Channels\Slack\InteractivityTracker;
use App\Channels\Slack\NotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['yak.channels.slack.bot_token' => 'xoxb-test']);
    Http::fake();
    InteractivityTracker::reset();
});

it('records a sent button-bearing clarification for the health check', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.2',
        'clarification_options' => ['acme/web', 'acme/api'],
    ]);

    (new NotificationDriver)->send($task, NotificationType::Clarification, 'Which repo?');

    expect(InteractivityTracker::sentCount())->toBe(1);
});

it('does not record a clarification with no options (those have no buttons)', function () {
    // Clarifications with no options render as a plain question — the
    // user responds in-thread, not via button — so they don't exercise
    // the Interactivity URL.
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.2',
        'clarification_options' => null,
    ]);

    (new NotificationDriver)->send($task, NotificationType::Clarification, 'Which repo?');

    expect(InteractivityTracker::sentCount())->toBe(0);
});

it('does not record non-Clarification notification types', function () {
    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.2',
    ]);

    (new NotificationDriver)->send($task, NotificationType::Result, 'All done!');

    expect(InteractivityTracker::sentCount())->toBe(0);
});
