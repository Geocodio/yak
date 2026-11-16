<?php

use App\DataTransferObjects\PreviewWakeOutcome;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Jobs\Threads\RestoreThreadPreviewJob;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use App\Services\Threads\ThreadSharing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\VisibilityRoutes;
use Tests\TestCase;

const PREVIEW_SWEEP_ROUTES = [
    'app.threads.preview.status',
    'app.threads.preview.warm',
    'app.threads.preview.heartbeat',
    'app.threads.preview.ticket',
    'app.threads.preview.restore',
];

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
});

/**
 * A private thread with a preview host and a destroyed sandbox, so every preview route has something to answer.
 *
 * @return array{0: Thread, 1: User}
 */
function previewSweepThread(): array
{
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner, [
        'title' => 'Pricing table on the plans page',
        'repo' => 'example/web',
        'preview_hostname' => 't-k3m9p2qa7x.yak.example.com',
        'sandbox_container' => 'yak-thread-1',
        'sandbox_state' => ThreadSandboxState::Destroyed,
    ]);

    return [$thread, $owner];
}

/**
 * Row counts of the tables a restore could touch.
 *
 * @return array<string, int>
 */
function previewSweepRowCounts(): array
{
    return collect(['threads', 'thread_restore_steps', 'jobs', 'task_runs'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

/**
 * @param  array{method: string, path: string, isDrive: bool, body: array<string, mixed>}  $probe
 * @param  array<string, string>  $headers
 */
function previewSweepRequest(TestCase $test, array $probe, string $threadKey, array $headers): TestResponse
{
    return $test->json($probe['method'], '/api/app/v1' . str_replace('{thread}', $threadKey, $probe['path']), $probe['body'], [...VisibilityRoutes::groupHeaders(), ...$headers]);
}

it('probes every preview route', function () {
    $probes = VisibilityRoutes::appThreadProbes();

    foreach (PREVIEW_SWEEP_ROUTES as $name) {
        expect(VisibilityRoutes::appThreads())->toContain($name)
            ->and($probes)->toHaveKey($name)
            ->and($probes[$name]['isDrive'])->toBe($name === 'app.threads.preview.restore');
    }
});

it('answers a stranger exactly like a missing thread on every preview route', function () {
    [$thread] = previewSweepThread();
    $headers = phoneHeadersFor(User::factory()->create());

    foreach (PREVIEW_SWEEP_ROUTES as $name) {
        $probe = VisibilityRoutes::appThreadProbes()[$name];
        $missing = previewSweepRequest($this, $probe, 'zzzzzzzz', $headers)->assertNotFound();

        previewSweepRequest($this, $probe, $thread->public_id, $headers)->assertNotFound()->assertExactJson($missing->json());
        previewSweepRequest($this, $probe, (string) $thread->id, $headers)->assertNotFound()->assertExactJson($missing->json());
    }
});

it('lets a share recipient use every preview read', function () {
    [$thread, $owner] = previewSweepThread();
    $thread->update(['sandbox_state' => ThreadSandboxState::Running]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->andReturn(PreviewWakeOutcome::waking([]));
    $headers = phoneHeadersFor($recipient);
    $probes = VisibilityRoutes::appThreadProbes();

    previewSweepRequest($this, $probes['app.threads.preview.status'], $thread->public_id, $headers)->assertOk();
    previewSweepRequest($this, $probes['app.threads.preview.warm'], $thread->public_id, $headers)->assertNoContent();
    previewSweepRequest($this, $probes['app.threads.preview.heartbeat'], $thread->public_id, $headers)->assertNoContent();
    previewSweepRequest($this, $probes['app.threads.preview.ticket'], $thread->public_id, $headers)->assertOk();
});

it('refuses a share recipient on restore and changes nothing', function () {
    Queue::fake();
    [$thread, $owner] = previewSweepThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $before = previewSweepRowCounts();

    previewSweepRequest($this, VisibilityRoutes::appThreadProbes()['app.threads.preview.restore'], $thread->public_id, phoneHeadersFor($recipient))
        ->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect(previewSweepRowCounts())->toBe($before);
    Queue::assertNotPushed(RestoreThreadPreviewJob::class);
    Queue::assertNothingPushed();
});
