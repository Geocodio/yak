<?php

use App\Models\Thread;
use App\Services\Push\Apns\ApnsPayloads;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
    fakeApns();
});

function pushFor(string $event, string $body = 'Yak has a question for you.', string $title = 'Retry failed uploads'): array
{
    return [
        'title' => $title,
        'body' => $body,
        'url' => '/threads/d0e515ef',
        'tag' => 'thread-1-' . $event,
        'event' => $event,
        'kind' => 'thread',
        'id' => 'd0e515ef',
    ];
}

function contentState(bool $isPrivate = false, string $step = 'Starting'): array
{
    return ['state' => 'working', 'step' => $step, 'turnStartedAt' => '2026-11-16T12:00:00+00:00', 'isPrivate' => $isPrivate];
}

it('builds a time-sensitive alert for needs you', function () {
    expect(app(ApnsPayloads::class)->alert(pushFor('needs_you'), 2))->toBe([
        'aps' => [
            'alert' => ['title' => 'Retry failed uploads', 'body' => 'Yak has a question for you.'],
            'sound' => 'default',
            'badge' => 2,
            'thread-id' => 'thread-d0e515ef',
            'interruption-level' => 'time-sensitive',
            'filter-criteria' => 'yak.example.com',
        ],
        'yak' => [
            'instance' => 'https://yak.example.com',
            'event' => 'needs_you',
            'kind' => 'thread',
            'id' => 'd0e515ef',
            'path' => '/threads/d0e515ef',
            'needsYouCount' => 2,
        ],
    ]);
});

it('uses the active level for every other event', function (string $event) {
    expect(app(ApnsPayloads::class)->alert(pushFor($event), 0)['aps']['interruption-level'])->toBe('active');
})->with(['turn_finished', 'pull_request', 'account']);

it('builds an account alert with no thread', function () {
    $push = ['title' => 'Test account', 'body' => 'Needs attention.', 'url' => '/settings/claude-accounts', 'tag' => 'account-1', 'event' => 'account', 'kind' => 'account', 'id' => null];

    $payload = app(ApnsPayloads::class)->alert($push, 0);

    expect($payload['aps']['thread-id'])->toBe('account')
        ->and($payload['yak']['id'])->toBeNull()
        ->and($payload['yak']['kind'])->toBe('account');
});

it('builds the start push of a team thread', function () {
    $thread = Thread::factory()->create(['public_id' => 'd0e515ef', 'title' => 'Retry failed uploads']);
    $timestamp = CarbonImmutable::now()->getTimestamp();

    expect(app(ApnsPayloads::class)->liveActivityStart($thread, contentState(), 'dGVzdC1jaGFubmVs', $timestamp))->toBe(['aps' => [
        'timestamp' => 1794830400,
        'event' => 'start',
        'content-state' => contentState(),
        'attributes-type' => 'ThreadActivityAttributes',
        'attributes' => ['instance' => 'https://yak.example.com', 'threadId' => 'd0e515ef', 'title' => 'Retry failed uploads'],
        'input-push-channel' => 'dGVzdC1jaGFubmVs',
        'stale-date' => 1794832200,
        'alert' => ['title' => 'Retry failed uploads', 'body' => 'Yak is working.'],
    ]]);
});

it('keeps the title of a private thread out of the start push', function () {
    $thread = Thread::factory()->private()->create(['public_id' => 'd0e515ef', 'title' => 'Secret plan']);

    $payload = app(ApnsPayloads::class)->liveActivityStart($thread, contentState(true, ''), 'dGVzdC1jaGFubmVs', 1794830400);

    expect($payload['aps']['attributes']['title'])->toBe('Private thread')
        ->and($payload['aps']['alert']['title'])->toBe('Private thread')
        ->and($payload['aps']['content-state']['step'])->toBe('')
        ->and(json_encode($payload))->not->toContain('Secret plan');
});

it('builds an update with a stale date and an end without one', function () {
    $payloads = app(ApnsPayloads::class);

    expect($payloads->liveActivityUpdate(contentState(step: 'Running a command'), 1794830460))->toBe(['aps' => [
        'timestamp' => 1794830460,
        'event' => 'update',
        'content-state' => contentState(step: 'Running a command'),
        'stale-date' => 1794832260,
    ]])
        ->and($payloads->liveActivityEnd(contentState(), 1794830500))->toBe(['aps' => [
            'timestamp' => 1794830500,
            'event' => 'end',
            'content-state' => contentState(),
        ]]);
});

it('hashes a collapse id to 32 hex characters', function () {
    $id = ApnsPayloads::collapseId('run-7');

    expect($id)->toMatch('/^[0-9a-f]{32}$/')
        ->and($id)->not->toContain('run-7')
        ->and(ApnsPayloads::collapseId('run-7'))->toBe($id)
        ->and(ApnsPayloads::collapseId('run-8'))->not->toBe($id);
});
