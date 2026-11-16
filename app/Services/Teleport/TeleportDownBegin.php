<?php

namespace App\Services\Teleport;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadVisibility;
use App\Exceptions\TeleportError;
use App\Jobs\PrepareTeleportDownJob;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Begin of a teleport down: the checks of contract section 8, then a teleport and its preparation job.
 */
class TeleportDownBegin
{
    public function __construct(private readonly ThreadAccess $access) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{teleport: Teleport, resumed: bool}
     */
    public function begin(Device $device, array $validated): array
    {
        $lock = Cache::lock('teleport-begin-down:' . $validated['thread_id'], 15);

        $result = $lock->block(10, fn (): array => DB::transaction(fn (): array => $this->beginLocked($device, $validated)));

        if (! $result['resumed']) {
            dispatch(new PrepareTeleportDownJob($result['teleport']->id));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{teleport: Teleport, resumed: bool}
     */
    private function beginLocked(Device $device, array $validated): array
    {
        $thread = Thread::query()->withoutGlobalScopes()->whereKey((int) $validated['thread_id'])->lockForUpdate()->first();

        if ($thread === null || ! $this->access->canSee($device->user, $thread)) {
            throw new TeleportError('thread_not_found', 'That thread does not exist.', 404);
        }

        if (! $this->access->canDrive($device->user, $thread)) {
            throw new TeleportError('forbidden', 'You cannot drive this thread.', 403);
        }

        $given = $validated['repos'][0]['repo'] ?? null;

        if ($given !== null && strtolower($given) !== strtolower($thread->repo)) {
            throw new TeleportError('wrong_repo', "This thread is on {$thread->repo}, not {$given}.", 422, ['thread_repo' => $thread->repo, 'given_repo' => $given]);
        }

        $open = $thread->openTeleport();

        if ($open !== null) {
            if ($open->device_id === $device->id && $open->direction === TeleportDirection::Down) {
                $open->touchCall();

                return ['teleport' => $open, 'resumed' => true];
            }

            throw new TeleportError('teleport_in_progress', 'Another teleport is already running for this thread.', 409, [
                'teleport_id' => $open->id,
                'direction' => $open->direction->value,
                'device_name' => $open->device?->name,
            ]);
        }

        $this->assertOwner($thread, $device);
        $repeat = $this->completedRepeat($thread, $device);

        if ($repeat !== null) {
            return ['teleport' => $repeat, 'resumed' => true];
        }

        $running = ThreadTurns::runningTurn($thread);
        $choice = $validated['running_turn'] ?? null;

        if ($running !== null && $choice === null) {
            throw new TeleportError('turn_running', 'A turn is running on this thread.', 409, ['turn_started_at' => $running->started_at->utc()->format('Y-m-d\TH:i:s\Z')]);
        }

        $isTeam = $thread->effectiveVisibility() === ThreadVisibility::Team;

        if ($isTeam && blank($validated['visibility'] ?? null)) {
            throw new TeleportError('visibility_choice_required', 'Choose whether the thread stays visible to the team.', 409, ['current' => 'team']);
        }

        $teleport = Teleport::query()->create([
            'thread_id' => $thread->id,
            'device_id' => $device->id,
            'user_id' => $device->user_id,
            'direction' => TeleportDirection::Down,
            'state' => $running !== null && $choice === 'wait' ? TeleportState::WaitingForTurn : TeleportState::Preparing,
            'running_turn' => $running === null ? null : $choice,
            'visibility_choice' => $isTeam ? $validated['visibility'] : null,
            'payload' => ['repos' => $validated['repos'] ?? [], 'local_path' => $validated['local_path'] ?? null, 'os' => $validated['os'] ?? null, 'arch' => $validated['arch'] ?? null],
            'last_call_at' => now(),
        ]);

        return ['teleport' => $teleport, 'resumed' => false];
    }

    private function assertOwner(Thread $thread, Device $device): void
    {
        if ($thread->owner_side !== ThreadOwnerSide::Device) {
            return;
        }

        if ($thread->owner_device_id === $device->id) {
            if ($this->completedRepeat($thread, $device) === null) {
                throw new TeleportError('already_on_this_device', 'This thread is already on this computer.');
            }

            return;
        }

        throw new TeleportError('thread_owned_by_device', 'This thread is on another of your computers. Teleport it up from there first.', 409, [
            'device_name' => Device::query()->find($thread->owner_device_id)?->name,
        ]);
    }

    private function completedRepeat(Thread $thread, Device $device): ?Teleport
    {
        if ($thread->owner_side !== ThreadOwnerSide::Device || $thread->owner_device_id !== $device->id) {
            return null;
        }

        $latest = Teleport::query()->where('thread_id', $thread->id)->where('device_id', $device->id)->latest('id')->first();

        return $latest !== null && $latest->direction === TeleportDirection::Down && $latest->state === TeleportState::Completed ? $latest : null;
    }
}
