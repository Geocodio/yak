<?php

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Events\DeviceRevoked;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\Devices\DeviceRevoker;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    [$this->device, $this->token] = makeDevice();
    $this->thread = Thread::factory()->create(['owner_side' => 'device', 'owner_device_id' => $this->device->id]);
});

it('shows a teleport to its device only', function () {
    $teleport = Teleport::factory()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);
    [, $otherToken] = makeDevice($this->device->user);

    $this->getJson("/api/cli/v1/teleports/{$teleport->id}", cliHeaders($this->token))
        ->assertOk()->assertJsonPath('teleport_id', $teleport->id)->assertJsonPath('state', 'open');
    $this->getJson("/api/cli/v1/teleports/{$teleport->id}", cliHeaders($otherToken))
        ->assertNotFound()->assertJsonPath('error', 'teleport_not_found');
});

it('cancels idempotently', function () {
    $teleport = Teleport::factory()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);
    $body = ['reason' => 'dirty_tree', 'detail' => 'uncommitted files'];

    $this->postJson("/api/cli/v1/teleports/{$teleport->id}/cancel", $body, cliHeaders($this->token))
        ->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('failure.code', 'dirty_tree');
    $this->postJson("/api/cli/v1/teleports/{$teleport->id}/cancel", $body, cliHeaders($this->token))
        ->assertOk()->assertJsonPath('state', 'cancelled');
});

it('refuses to cancel a completed teleport', function () {
    $teleport = Teleport::factory()->inState(TeleportState::Completed)->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);

    $this->postJson("/api/cli/v1/teleports/{$teleport->id}/cancel", ['reason' => 'error'], cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'teleport_completed');
});

it('clears the web mark when a down teleport is cancelled and leaves the owner alone', function () {
    $this->thread->update(['waiting_for_computer_at' => now(), 'teleport_requested_running_turn' => 'wait']);
    $teleport = Teleport::factory()->down()->inState(TeleportState::WaitingForTurn)->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);

    $this->postJson("/api/cli/v1/teleports/{$teleport->id}/cancel", ['reason' => 'user_cancelled'], cliHeaders($this->token))->assertOk();

    $fresh = $this->thread->refresh();
    expect($fresh->waiting_for_computer_at)->toBeNull()
        ->and($fresh->teleport_requested_running_turn)->toBeNull()
        ->and($fresh->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and($fresh->owner_device_id)->toBe($this->device->id);
});

it('expires stale teleports and clears marks', function () {
    $this->thread->update(['waiting_for_computer_at' => now()]);
    $stale = Teleport::factory()->down()->inState(TeleportState::WaitingForTurn)->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'expires_at' => now()->subMinute()]);
    $fresh = Teleport::factory()->create(['thread_id' => Thread::factory()->create()->id, 'device_id' => $this->device->id]);

    Artisan::call('yak:expire-teleports');

    expect($stale->refresh()->state)->toBe(TeleportState::Expired)
        ->and($fresh->refresh()->state)->toBe(TeleportState::Open)
        ->and($this->thread->refresh()->waiting_for_computer_at)->toBeNull();
});

it('answers calls on an expired teleport with teleport_expired', function () {
    config(['yak.teleport.expiry_minutes' => 45]);
    $teleport = Teleport::factory()->up()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'expires_at' => now()->subMinute()]);
    Artisan::call('yak:expire-teleports');
    expect($teleport->refresh()->state)->toBe(TeleportState::Expired);

    $this->getJson("/api/cli/v1/threads/{$this->thread->public_id}/session/length?session_id=a0a0a0a0-0000-4000-8000-000000000001&rel_path=a0a0a0a0-0000-4000-8000-000000000001.jsonl", cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'teleport_expired')->assertJsonPath('details.minutes', 45);
});

it('expires lazily when a stale teleport is read', function () {
    $teleport = Teleport::factory()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id, 'expires_at' => now()->subMinute()]);

    $this->getJson("/api/cli/v1/teleports/{$teleport->id}", cliHeaders($this->token))
        ->assertOk()->assertJsonPath('state', 'expired');
});

it('cancels the open teleports of a revoked device', function () {
    $open = Teleport::factory()->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);
    $done = Teleport::factory()->inState(TeleportState::Completed)->create(['thread_id' => Thread::factory()->create()->id, 'device_id' => $this->device->id]);

    app(DeviceRevoker::class)->revoke($this->device);
    DeviceRevoked::dispatch($this->device, []);

    expect($open->refresh()->state)->toBe(TeleportState::Cancelled)
        ->and($open->failure_code)->toBe('device_revoked')
        ->and($done->refresh()->state)->toBe(TeleportState::Completed);
});

it('keeps owner fields consistent when a cancel follows the revoker hand-back', function () {
    $this->thread->update(['waiting_for_computer_at' => now()]);
    $teleport = Teleport::factory()->down()->inState(TeleportState::WaitingForTurn)->create(['thread_id' => $this->thread->id, 'device_id' => $this->device->id]);

    // The revoker hands the thread back to Yak, then announces the revocation.
    app(DeviceRevoker::class)->revoke($this->device);
    $this->thread->forceFill(['owner_side' => ThreadOwnerSide::Yak, 'owner_device_id' => null])->save();
    DeviceRevoked::dispatch($this->device, [$this->thread->id]);

    $fresh = $this->thread->refresh();
    expect($teleport->refresh()->state)->toBe(TeleportState::Cancelled)
        ->and($teleport->direction)->toBe(TeleportDirection::Down)
        ->and($fresh->owner_side)->toBe(ThreadOwnerSide::Yak)
        ->and($fresh->owner_device_id)->toBeNull()
        ->and($fresh->waiting_for_computer_at)->toBeNull();
});
