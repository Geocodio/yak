<?php

use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Queue;

/**
 * Sign a Sentry webhook payload using HMAC-SHA256.
 */
function signSentryPayload(string $body, string $secret): string
{
    return hash_hmac('sha256', $body, $secret);
}

/**
 * Build a Sentry issue alert webhook payload.
 *
 * @param  array<string, mixed>  $overrides
 */
function sentryAlertPayload(array $overrides = []): string
{
    $issueId = $overrides['issueId'] ?? '12345';
    $title = $overrides['title'] ?? 'TypeError: Cannot read property of undefined';
    $culprit = $overrides['culprit'] ?? 'app/utils/auth.js';
    $count = $overrides['count'] ?? 10;
    $firstSeen = $overrides['firstSeen'] ?? '2026-04-01T12:00:00.000Z';
    $userCount = $overrides['userCount'] ?? 5;
    $projectSlug = $overrides['projectSlug'] ?? 'my-sentry-project';
    $actionability = $overrides['seerActionability'] ?? 'high';

    $tags = $overrides['tags'] ?? [
        ['key' => 'yak-eligible', 'value' => 'yes'],
    ];

    $frames = $overrides['frames'] ?? [
        ['filename' => 'app/utils/auth.js', 'function' => 'validateToken', 'lineno' => 42],
        ['filename' => 'app/middleware/auth.js', 'function' => 'checkAuth', 'lineno' => 15],
    ];

    $entries = [];
    if ($frames !== []) {
        $entries[] = [
            'type' => 'exception',
            'data' => [
                'values' => [
                    [
                        'type' => 'TypeError',
                        'value' => 'Cannot read property of undefined',
                        'stacktrace' => ['frames' => $frames],
                    ],
                ],
            ],
        ];
    }

    $payload = [
        'action' => $overrides['action'] ?? 'triggered',
        'data' => [
            'issue' => [
                'id' => $issueId,
                'title' => $title,
                'culprit' => $culprit,
                'count' => $count,
                'firstSeen' => $firstSeen,
                'userCount' => $userCount,
                'seerActionability' => $actionability,
                'project' => [
                    'slug' => $projectSlug,
                ],
            ],
            'event' => [
                'tags' => $tags,
                'entries' => $entries,
            ],
        ],
    ];

    return (string) json_encode($payload);
}

/**
 * Enable the Sentry channel and re-register routes.
 */
function enableSentryChannel(): string
{
    $secret = 'test-sentry-webhook-secret';

    config()->set('yak.channels.sentry', [
        'driver' => 'sentry',
        'auth_token' => 'sentry_test_auth_token',
        'webhook_secret' => $secret,
        'org_slug' => 'test-org',
        'region_url' => 'https://us.sentry.io',
        'min_events' => 5,
        'min_actionability' => 'medium',
    ]);

    // Re-register routes so the Sentry route is available
    (new ChannelServiceProvider(app()))->boot();

    return $secret;
}

/*
|--------------------------------------------------------------------------
| Signature Verification
|--------------------------------------------------------------------------
*/

it('rejects requests with invalid Sentry signature', function () {
    $secret = enableSentryChannel();
    $body = sentryAlertPayload();

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => 'invalid_signature',
        'CONTENT_TYPE' => 'application/json',
    ])->assertUnauthorized();
});

it('rejects requests with missing Sentry signature', function () {
    enableSentryChannel();
    $body = sentryAlertPayload();

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'CONTENT_TYPE' => 'application/json',
    ])->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Valid Payload Creates Task
|--------------------------------------------------------------------------
*/

it('creates a task from a valid Sentry alert with yak-eligible tag', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'slug' => 'my-app',
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99001',
        'title' => 'TypeError: Cannot read property of undefined',
        'culprit' => 'app/utils/auth.js',
        'count' => 10,
        'firstSeen' => '2026-04-01T12:00:00.000Z',
        'userCount' => 5,
        'seerActionability' => 'high',
        'projectSlug' => 'my-sentry-project',
    ]);
    $signature = signSentryPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertStatus(201);

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->source)->toBe('sentry');
    expect($task->external_id)->toBe('99001');
    expect($task->repo)->toBe('my-app');
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->description)->toContain('TypeError: Cannot read property of undefined');
    expect($task->description)->toContain('app/utils/auth.js');
    expect($task->description)->toContain('10');
    expect($task->description)->toContain('2026-04-01T12:00:00.000Z');
    expect($task->description)->toContain('5');

    Queue::assertPushed(RunYakJob::class, function (RunYakJob $job) use ($task) {
        return $job->task->id === $task->id;
    });
});

it('includes stacktrace frames in task description', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'slug' => 'my-app',
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99002',
        'seerActionability' => 'high',
        'count' => 10,
        'frames' => [
            ['filename' => 'app/handler.js', 'function' => 'handle', 'lineno' => 10],
            ['filename' => 'app/router.js', 'function' => 'dispatch', 'lineno' => 55],
        ],
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertStatus(201);

    $task = YakTask::first();
    expect($task->description)->toContain('app/handler.js:10 in handle');
    expect($task->description)->toContain('app/router.js:55 in dispatch');
});

/*
|--------------------------------------------------------------------------
| Repo Resolution from sentry_project Column
|--------------------------------------------------------------------------
*/

it('resolves repo from sentry_project column on repositories table', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'slug' => 'acme/api',
        'sentry_project' => 'acme-api-prod',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99003',
        'projectSlug' => 'acme-api-prod',
        'seerActionability' => 'high',
        'count' => 10,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertStatus(201);

    $task = YakTask::first();
    expect($task->repo)->toBe('acme/api');
});

it('rejects payload when sentry_project does not match any repository', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    $body = sentryAlertPayload([
        'issueId' => '99004',
        'projectSlug' => 'unknown-project',
        'seerActionability' => 'high',
        'count' => 10,
    ]);
    $signature = signSentryPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();
    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Inactive Repo Ignored
|--------------------------------------------------------------------------
*/

it('ignores inactive repo Sentry project', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->inactive()->withSentry()->create([
        'slug' => 'inactive-app',
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99005',
        'projectSlug' => 'my-sentry-project',
        'seerActionability' => 'high',
        'count' => 10,
    ]);
    $signature = signSentryPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();
    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| CSP Violation Filtering
|--------------------------------------------------------------------------
*/

it('rejects CSP violations from culprit', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'slug' => 'my-app',
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99010',
        'culprit' => 'font-src',
        'title' => 'CSP violation detected',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();
    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

it('rejects CSP violations from script-src-elem culprit', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99011',
        'culprit' => 'script-src-elem',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

it('rejects issues with title starting with Blocked', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99012',
        'title' => 'Blocked inline script execution',
        'culprit' => 'app/main.js',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Transient Infrastructure Error Filtering
|--------------------------------------------------------------------------
*/

it('rejects RedisException errors', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99020',
        'title' => 'RedisException: Connection lost',
        'culprit' => 'app/cache/redis.php',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

it('rejects Predis connection errors', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99021',
        'culprit' => 'Predis\\Connection\\ConnectionException',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

it('rejects php_network_getaddresses errors', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99022',
        'title' => 'php_network_getaddresses: getaddrinfo failed',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

it('rejects context deadline exceeded errors', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99023',
        'title' => 'context deadline exceeded',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

it('rejects Connection refused errors', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99024',
        'title' => 'Connection refused',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

it('rejects Operation timed out errors', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99025',
        'title' => 'Operation timed out',
        'seerActionability' => 'high',
        'count' => 100,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Seer Actionability Filtering
|--------------------------------------------------------------------------
*/

it('rejects issues with actionability below medium', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99030',
        'seerActionability' => 'low',
        'count' => 10,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Event Count Filtering
|--------------------------------------------------------------------------
*/

it('rejects issues with event count below 5', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99040',
        'seerActionability' => 'high',
        'count' => 3,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Priority Bypass
|--------------------------------------------------------------------------
*/

it('yak-priority tag bypasses event count filter', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'slug' => 'my-app',
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99050',
        'seerActionability' => 'high',
        'count' => 1,
        'tags' => [
            ['key' => 'yak-eligible', 'value' => 'yes'],
            ['key' => 'yak-priority', 'value' => 'yes'],
        ],
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertStatus(201);

    expect(YakTask::count())->toBe(1);
    Queue::assertPushed(RunYakJob::class);
});

it('yak-priority tag bypasses actionability filter', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'slug' => 'my-app',
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99051',
        'seerActionability' => 'low',
        'count' => 1,
        'tags' => [
            ['key' => 'yak-eligible', 'value' => 'yes'],
            ['key' => 'yak-priority', 'value' => 'yes'],
        ],
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertStatus(201);

    expect(YakTask::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Deduplication
|--------------------------------------------------------------------------
*/

it('returns 409 for duplicate external_id and repo', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'slug' => 'my-app',
        'sentry_project' => 'my-sentry-project',
    ]);

    YakTask::factory()->create([
        'source' => 'sentry',
        'external_id' => '99060',
        'repo' => 'my-app',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99060',
        'seerActionability' => 'high',
        'count' => 10,
    ]);
    $signature = signSentryPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertStatus(409);
    expect(YakTask::where('external_id', '99060')->count())->toBe(1);
    Queue::assertNotPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Non-triggered Actions Ignored
|--------------------------------------------------------------------------
*/

it('ignores non-triggered action events', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    $body = sentryAlertPayload(['action' => 'resolved']);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Missing yak-eligible Tag Ignored
|--------------------------------------------------------------------------
*/

it('ignores events without yak-eligible tag', function () {
    $secret = enableSentryChannel();
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99070',
        'tags' => [
            ['key' => 'environment', 'value' => 'production'],
        ],
        'seerActionability' => 'high',
        'count' => 10,
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});
