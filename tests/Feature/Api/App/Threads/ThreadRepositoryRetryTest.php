<?php

use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Jobs\RetryRepositorySetupJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BusyRow;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    Queue::fake();
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = privateThreadOf($this->owner, ['title' => 'Move search to the new index', 'repo' => 'example/api']);
    $this->row = ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'example/web', 'setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'boom']);
});

function retryPath(Thread $thread, ThreadRepository|string $row): string
{
    return appThreadPath($thread, '/repositories/' . ($row instanceof ThreadRepository ? $row->id : $row) . '/retry');
}

it('retries a failed setup', function () {
    $response = $this->postJson(retryPath($this->thread, $this->row), [], phoneHeadersFor($this->owner))->assertOk()->assertExactJson(['status' => 'retrying']);

    expect($this->row->fresh()->setup_status)->toBe(RepositorySetupStatus::Pending);
    Queue::assertPushed(RetryRepositorySetupJob::class, 1);
    ContractFixture::assertMatches('app', 'thread-repository-retry', $response->json());
});

it('answers retrying again and dispatches nothing', function () {
    $headers = phoneHeadersFor($this->owner);
    $this->postJson(retryPath($this->thread, $this->row), [], $headers)->assertOk();

    $this->postJson(retryPath($this->thread, $this->row), [], $headers)->assertOk()->assertExactJson(['status' => 'retrying']);

    Queue::assertPushed(RetryRepositorySetupJob::class, 1);
});

it('refuses what cannot be retried', function () {
    $headers = phoneHeadersFor($this->owner);
    $primary = ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'example/api', 'role' => 'primary']);
    $ready = ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'example/docs', 'setup_status' => RepositorySetupStatus::Ready]);

    foreach ([$primary, $ready] as $row) {
        $this->postJson(retryPath($this->thread, $row), [], $headers)->assertStatus(409)->assertExactJson([
            'error' => 'nothing_to_retry',
            'message' => 'Only a failed setup of an extra repository can be retried.',
        ]);
    }

    $this->thread->forceFill(['status' => ThreadStatus::Closed, 'closed_at' => now(), 'close_reason' => 'manual'])->save();
    $this->postJson(retryPath($this->thread, $this->row), [], $headers)->assertStatus(409)->assertJsonPath('error', 'thread_closed')->assertJsonPath('message', 'This thread is closed.');
    Queue::assertNothingPushed();
});

it('does not find another thread\'s repository', function () {
    $headers = phoneHeadersFor($this->owner);
    $other = privateThreadOf($this->owner, ['repo' => 'example/api']);
    $otherRow = ThreadRepository::factory()->changes()->create(['thread_id' => $other->id, 'repo' => 'example/web', 'setup_status' => RepositorySetupStatus::Failed]);

    $missing = $this->postJson(retryPath($this->thread, '999999'), [], $headers)->assertNotFound();
    $this->postJson(retryPath($this->thread, $otherRow), [], $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson(retryPath($this->thread, 'abc'), [], $headers)->assertNotFound();
    expect($otherRow->fresh()->setup_status)->toBe(RepositorySetupStatus::Failed);
});

it('answers busy when the lock wait runs out', function () {
    $busy = BusyRow::on(ThreadRepository::class, $this->row);
    $headers = phoneHeadersFor($this->owner);

    $this->postJson(retryPath($this->thread, $this->row), [], $headers)->assertStatus(503)->assertJsonPath('error', 'unavailable');
    expect($this->row->fresh()->setup_status)->toBe(RepositorySetupStatus::Failed);

    $busy->busy = false;
    $this->postJson(retryPath($this->thread, $this->row), [], $headers)->assertOk();
});

it('lets a share recipient read but not retry', function () {
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($this->thread, $this->owner, ThreadShareMode::People, [$recipient->id]);

    $this->postJson(retryPath($this->thread, $this->row), [], phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect($this->row->fresh()->setup_status)->toBe(RepositorySetupStatus::Failed);
    Queue::assertNothingPushed();
});

it('answers a hidden thread like a missing one', function () {
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/repositories/1/retry', [], $headers)->assertNotFound();
    $this->postJson(retryPath($this->thread, $this->row), [], $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = retryPath($this->thread, $this->row);

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
