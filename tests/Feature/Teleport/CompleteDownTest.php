<?php

use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadVisibility;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Testing\TestResponse;

const DOWN_COMPLETE_SESSION = 'c7c7c7c7-0000-4000-8000-000000000008';

beforeEach(function () {
    [$this->device, $this->token] = makeDevice();
    $this->thread = Thread::factory()->create(['repo' => 'acme/widgets', 'waiting_for_computer_at' => now()]);
    $this->sandbox = Mockery::mock(ThreadSandbox::class);
    $this->sandbox->shouldReceive('touch')->byDefault();
    app()->instance(ThreadSandbox::class, $this->sandbox);
    $this->teleport = Teleport::factory()->down()->inState(TeleportState::Ready)->create([
        'thread_id' => $this->thread->id,
        'device_id' => $this->device->id,
        'visibility_choice' => 'keep',
        'manifest' => ['session' => ['session_id' => DOWN_COMPLETE_SESSION, 'files' => [['rel_path' => DOWN_COMPLETE_SESSION . '.jsonl', 'mode' => 'append', 'length' => 3, 'sha256' => str_repeat('a', 64)]]]],
    ]);
});

function completeDownBody(array $overrides = []): array
{
    return [
        'applied' => [['repo' => 'acme/widgets', 'status' => 'applied', 'mode' => 'fast_forward', 'head_sha' => 'def456', 'branch' => 'yak/fix', 'path' => '/Users/ada/widgets']],
        'downloaded' => [['session_id' => DOWN_COMPLETE_SESSION, 'rel_path' => DOWN_COMPLETE_SESSION . '.jsonl', 'sha256' => str_repeat('a', 64)]],
        ...$overrides,
    ];
}

function completeDown(object $test, array $overrides = []): TestResponse
{
    return $test->postJson("/api/cli/v1/teleports/{$test->teleport->id}/complete", completeDownBody($overrides), cliHeaders($test->token));
}

it('completes down and moves ownership to the device', function () {
    $this->sandbox->shouldReceive('touch')->once();

    completeDown($this)->assertOk()
        ->assertJsonPath('ownership', 'device')
        ->assertJsonPath('owner_device.id', $this->device->id)
        ->assertJsonPath('visibility', 'team')
        ->assertJsonPath('marker.owner', 'device')
        ->assertJsonPath('marker.stack_messages_url', url("/api/cli/v1/threads/{$this->thread->public_id}/stack-messages"))
        ->assertJsonPath('teleport.state', 'completed');

    $thread = $this->thread->refresh();
    expect($thread->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and($thread->owner_device_id)->toBe($this->device->id)
        ->and($thread->waiting_for_computer_at)->toBeNull();
    expect($this->device->refresh())->last_teleport_thread_id->toBe($this->thread->id);
});

it('rejects a primary repo that was not applied', function () {
    completeDown($this, ['applied' => [['repo' => 'acme/widgets', 'status' => 'failed', 'reason' => 'dirty']]])
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    expect($this->thread->refresh()->owner_side)->toBe(ThreadOwnerSide::Yak);
});

it('rejects a missing or wrong session download', function () {
    completeDown($this, ['downloaded' => []])->assertStatus(409)->assertJsonPath('error', 'session_incomplete');
    completeDown($this, ['downloaded' => [['session_id' => DOWN_COMPLETE_SESSION, 'rel_path' => DOWN_COMPLETE_SESSION . '.jsonl', 'sha256' => str_repeat('b', 64)]]])
        ->assertStatus(409)->assertJsonPath('error', 'session_incomplete');

    expect($this->thread->refresh()->owner_side)->toBe(ThreadOwnerSide::Yak);
});

it('records extra repos that failed or were skipped', function () {
    $applied = [
        ...completeDownBody()['applied'],
        ['repo' => 'acme/lib', 'status' => 'failed', 'reason' => 'clone failed'],
        ['repo' => 'acme/docs', 'status' => 'skipped', 'reason' => 'no access'],
    ];

    completeDown($this, ['applied' => $applied, 'local_session_backup' => 'x.local-2026-11-13.jsonl'])->assertOk();

    $payload = $this->teleport->refresh()->payload;
    expect(collect($payload['applied'])->pluck('status')->all())->toBe(['applied', 'failed', 'skipped'])
        ->and($payload['local_session_backup'])->toBe('x.local-2026-11-13.jsonl');
});

it('makes the thread private to the device user when asked', function () {
    $this->teleport->update(['visibility_choice' => 'private']);

    completeDown($this)->assertOk()->assertJsonPath('visibility', 'private');

    expect($this->thread->refresh())->visibility->toBe(ThreadVisibility::Private)->owner_user_id->toBe($this->device->user_id);
});

it('keeps team visibility on keep', function () {
    completeDown($this)->assertOk();

    expect($this->thread->refresh()->visibility)->toBe(ThreadVisibility::Team);
});

it('requires the teleport to be ready', function () {
    $this->teleport->update(['state' => TeleportState::Preparing]);

    completeDown($this)->assertStatus(409)->assertJsonPath('error', 'no_open_teleport');
});

it('leaves the owner alone when the person cancels a ready teleport', function () {
    $this->postJson("/api/cli/v1/teleports/{$this->teleport->id}/cancel", ['reason' => 'dirty_tree'], cliHeaders($this->token))
        ->assertOk()->assertJsonPath('state', 'cancelled');

    $thread = $this->thread->refresh();
    expect($thread->owner_side)->toBe(ThreadOwnerSide::Yak)->and($thread->waiting_for_computer_at)->toBeNull();
});

it('returns the same body when completed again', function () {
    $first = completeDown($this)->assertOk()->json();
    $second = completeDown($this)->assertOk()->json();

    expect($second['marker'])->toBe($first['marker'])->and($second['ownership'])->toBe('device');
});

it('accepts a failed extra and keeps its reason without urls', function () {
    $applied = [
        ...completeDownBody()['applied'],
        ['repo' => 'acme/lib', 'status' => 'failed', 'reason' => 'clone of https://x:secret@github.com/acme/lib.git failed'],
    ];

    completeDown($this, ['applied' => $applied])->assertOk();

    $entry = $this->teleport->refresh()->payload['applied'][1];
    expect($entry['status'])->toBe('failed')
        ->and($entry['reason'])->toBe('clone of [url] failed');
});

it('still rejects a failed primary', function () {
    completeDown($this, ['applied' => [['repo' => 'acme/widgets', 'status' => 'failed', 'reason' => 'dirty'], ['repo' => 'acme/lib', 'status' => 'applied']]])
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
});
