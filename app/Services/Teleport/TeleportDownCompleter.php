<?php

namespace App\Services\Teleport;

use App\Enums\TeleportState;
use App\Enums\ThreadVisibility;
use App\Exceptions\TeleportError;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\DB;

/**
 * Teleport down, last step: the device applied the repo and wrote the session, so ownership moves to it.
 */
class TeleportDownCompleter
{
    public function __construct(
        private readonly OwnershipMover $mover,
        private readonly ThreadSandbox $sandbox,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function complete(Device $device, Teleport $teleport, array $validated): array
    {
        $teleport->touchCall();

        switch ($teleport->state) {
            case TeleportState::Completed:
                return $this->respond($teleport);
            case TeleportState::Failed:
                throw new TeleportError('teleport_failed', (string) $teleport->failure_message, 409, ['code' => $teleport->failure_code, 'message' => $teleport->failure_message]);
            case TeleportState::Cancelled:
                throw new TeleportError('teleport_cancelled', 'This teleport was cancelled.');
            case TeleportState::Expired:
                throw new TeleportError('teleport_expired', 'This teleport expired. Start it again.');
            case TeleportState::Ready:
                break;
            default:
                throw new TeleportError('no_open_teleport', 'This teleport is not ready to complete.');
        }

        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);
        $this->assertPrimaryApplied($thread, $validated['applied']);
        $this->assertSessionDownloaded($teleport, $validated['downloaded']);

        $teleport->forceFill(['payload' => [...($teleport->payload ?? []), 'applied' => $validated['applied'], 'local_session_backup' => $validated['local_session_backup'] ?? null]])->save();

        DB::transaction(function () use ($thread, $device, $teleport): void {
            if ($teleport->visibility_choice === 'private') {
                Thread::query()->withoutGlobalScopes()->whereKey($thread->id)->update(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $device->user_id]);
            }

            $this->mover->toDevice($thread, $device, $teleport);
        });

        $this->sandbox->touch($thread->refresh());

        return $this->respond($teleport->refresh());
    }

    /**
     * @param  list<array<string, mixed>>  $applied
     */
    private function assertPrimaryApplied(Thread $thread, array $applied): void
    {
        $primary = collect($applied)->first(fn (array $entry): bool => strtolower((string) $entry['repo']) === strtolower($thread->repo));

        if ($primary === null || ($primary['status'] ?? null) !== 'applied') {
            throw new TeleportError('validation_failed', 'The primary repository must be applied. Cancel the teleport otherwise.', 422, ['repo' => $thread->repo]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $downloaded
     */
    private function assertSessionDownloaded(Teleport $teleport, array $downloaded): void
    {
        $sessionId = $teleport->manifest['session']['session_id'] ?? null;
        /** @var list<array{rel_path: string, sha256: ?string}> $files */
        $files = $teleport->manifest['session']['files'] ?? [];
        $expected = collect($files)->firstWhere('rel_path', $sessionId . '.jsonl');
        $got = collect($downloaded)->first(fn (array $entry): bool => ($entry['rel_path'] ?? null) === $sessionId . '.jsonl');

        if ($sessionId === null || $expected === null || $got === null || ($got['sha256'] ?? null) !== $expected['sha256']) {
            throw new TeleportError('session_incomplete', 'The session was not downloaded completely.');
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function respond(Teleport $teleport): array
    {
        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);
        $device = $teleport->device;

        return ['status' => 200, 'body' => [
            'teleport' => $teleport->toApi(),
            'ownership' => 'device',
            'owner_device' => ['id' => $device?->id, 'name' => $device?->name],
            'visibility' => $thread->visibility->value,
            'marker' => [
                'thread_id' => $thread->id,
                'teleport_id' => $teleport->id,
                'owner' => 'device',
                'stack_messages_url' => url("/api/cli/v1/threads/{$thread->id}/stack-messages"),
            ],
        ]];
    }
}
