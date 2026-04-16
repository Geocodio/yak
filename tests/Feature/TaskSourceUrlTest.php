<?php

use App\Models\YakTask;
use App\Support\TaskSourceUrl;

it('builds a Slack archive URL when workspace_url is configured', function () {
    config()->set('yak.channels.slack.workspace_url', 'https://acme.slack.com');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C1234567',
        'slack_thread_ts' => '1700000000.123456',
    ]);

    expect(TaskSourceUrl::resolve($task))
        ->toBe('https://acme.slack.com/archives/C1234567/p1700000000123456');
});

it('returns null for Slack when workspace_url is not configured', function () {
    config()->set('yak.channels.slack.workspace_url', null);

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => 'C1234567',
        'slack_thread_ts' => '1700000000.123456',
    ]);

    expect(TaskSourceUrl::resolve($task))->toBeNull();
});

it('returns null for Slack when channel or thread_ts is missing', function () {
    config()->set('yak.channels.slack.workspace_url', 'https://acme.slack.com');

    $task = YakTask::factory()->create([
        'source' => 'slack',
        'slack_channel' => null,
        'slack_thread_ts' => null,
    ]);

    expect(TaskSourceUrl::resolve($task))->toBeNull();
});

it('returns the stored external_url for Linear tasks', function () {
    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_url' => 'https://linear.app/acme/issue/ACM-42/fix-the-bug',
    ]);

    expect(TaskSourceUrl::resolve($task))
        ->toBe('https://linear.app/acme/issue/ACM-42/fix-the-bug');
});

it('returns the stored external_url for Sentry tasks', function () {
    $task = YakTask::factory()->create([
        'source' => 'sentry',
        'external_url' => 'https://sentry.io/organizations/acme/issues/12345/',
    ]);

    expect(TaskSourceUrl::resolve($task))
        ->toBe('https://sentry.io/organizations/acme/issues/12345/');
});

it('returns null for unrecognised sources', function () {
    $task = YakTask::factory()->create([
        'source' => 'system',
    ]);

    expect(TaskSourceUrl::resolve($task))->toBeNull();
});

it('returns null when Linear external_url is empty', function () {
    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_url' => null,
    ]);

    expect(TaskSourceUrl::resolve($task))->toBeNull();
});
