<?php

use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\User;
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
 * Build a Sentry issue alert (`event_alert`) webhook payload in the shape
 * Sentry documents: everything lives under `data.event`, the project is a
 * numeric ID, and the slug only appears in the event's API URL.
 *
 * @param  array<string, mixed>  $overrides
 */
function sentryAlertPayload(array $overrides = []): string
{
    $issueId = $overrides['issueId'] ?? '12345';
    $projectSlug = $overrides['projectSlug'] ?? 'my-sentry-project';

    $tags = $overrides['tags'] ?? [
        ['yak-eligible', 'yes'],
    ];

    $frames = $overrides['frames'] ?? [
        ['filename' => 'app/utils/auth.js', 'function' => 'validateToken', 'lineno' => 42],
        ['filename' => 'app/middleware/auth.js', 'function' => 'checkAuth', 'lineno' => 15],
    ];

    $payload = [
        'action' => $overrides['action'] ?? 'triggered',
        'actor' => ['id' => 'sentry', 'name' => 'Sentry', 'type' => 'application'],
        'data' => [
            'event' => [
                'event_id' => 'e4874d664c3540c1a32eab185f12c5ab',
                'issue_id' => $issueId,
                'issue_url' => "https://sentry.io/api/0/issues/{$issueId}/",
                'project' => 1,
                'title' => $overrides['title'] ?? 'TypeError: Cannot read property of undefined',
                'culprit' => $overrides['culprit'] ?? 'app/utils/auth.js',
                'tags' => $tags,
                'exception' => [
                    'values' => [
                        [
                            'type' => 'TypeError',
                            'value' => 'Cannot read property of undefined',
                            'stacktrace' => ['frames' => $frames],
                        ],
                    ],
                ],
                'url' => "https://sentry.io/api/0/projects/test-org/{$projectSlug}/events/e4874d664c3540c1a32eab185f12c5ab/",
                'web_url' => "https://sentry.io/organizations/test-org/issues/{$issueId}/events/e4874d664c3540c1a32eab185f12c5ab/",
            ],
            'triggered_rule' => 'Send to Yak',
            'issue_alert' => ['title' => 'Send to Yak', 'settings' => []],
        ],
        'installation' => ['uuid' => 'a8e5d37a-696c-4c54-adb5-b3f28d64c7de'],
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
    ])->assertForbidden();
});

it('rejects requests with missing Sentry signature', function () {
    enableSentryChannel();
    $body = sentryAlertPayload();

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'CONTENT_TYPE' => 'application/json',
    ])->assertForbidden();
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
    expect($task->external_url)->toBe('https://sentry.io/organizations/test-org/issues/99001/');
    expect($task->repo)->toBe('my-app');
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->description)->toContain('TypeError: Cannot read property of undefined');
    expect($task->description)->toContain('app/utils/auth.js');
    expect($task->description)->toContain('https://sentry.io/organizations/test-org/issues/99001/');

    $context = json_decode($task->context, true);
    expect($context['error'])->toBe('TypeError: Cannot read property of undefined')
        ->and($context['culprit'])->toBe('app/utils/auth.js')
        ->and($context['stacktrace'])->toContain('app/utils/auth.js:42 in validateToken');

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
| Optional Required Tag
|--------------------------------------------------------------------------
|
| The alert rule pointed at Yak is the opt-in. An extra per-event tag gate
| is available for installs that want one, but it stays off by default: the
| tag has to be set in application code when the error is thrown, which is
| the wrong place to decide whether an issue is worth fixing.
|
*/

it('processes events with no opt-in tag when no required tag is configured', function () {
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
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(1);
    Queue::assertPushed(RunYakJob::class);
});

it('ignores events missing the required tag when one is configured', function () {
    $secret = enableSentryChannel();
    config(['yak.channels.sentry.required_tag' => 'yak-eligible']);
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    $body = sentryAlertPayload([
        'issueId' => '99070',
        'tags' => [
            ['key' => 'environment', 'value' => 'production'],
        ],
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful()
        ->assertJson(['filtered' => 'missing_tag:yak-eligible']);

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

it('accepts the required tag when Sentry sends tags as [key, value] pairs', function () {
    $secret = enableSentryChannel();
    config(['yak.channels.sentry.required_tag' => 'yak-eligible']);
    Queue::fake();

    Repository::factory()->withSentry()->create([
        'sentry_project' => 'my-sentry-project',
    ]);

    // Sentry serializes event tags as objects in some payloads and as plain
    // pairs in others. Both have to satisfy the gate.
    $body = sentryAlertPayload([
        'issueId' => '99071',
        'tags' => [
            ['environment', 'production'],
            ['yak-eligible', 'yes'],
        ],
    ]);
    $signature = signSentryPayload($body, $secret);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(1);
    Queue::assertPushed(RunYakJob::class);
});

it('makes the repository default responsible user own Sentry tasks', function () {
    $secret = enableSentryChannel();
    Queue::fake();
    $owner = User::factory()->create(['name' => 'Repo Owner']);
    Repository::factory()->withSentry()->create([
        'slug' => 'my-app',
        'sentry_project' => 'my-sentry-project',
        'default_responsible_user_id' => $owner->id,
    ]);

    $body = sentryAlertPayload(['issueId' => '99002', 'projectSlug' => 'my-sentry-project']);

    $this->call('POST', '/webhooks/sentry', content: $body, server: [
        'HTTP_Sentry-Hook-Signature' => signSentryPayload($body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ])->assertStatus(201);

    $task = YakTask::first();
    expect($task->author_name)->toBeNull()
        ->and($task->responsible_name)->toBe('Repo Owner')
        ->and($task->started_by_user_id)->toBeNull()
        ->and($task->responsible_user_id)->toBe($owner->id);
});
