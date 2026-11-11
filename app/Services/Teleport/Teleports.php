<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadStatus;
use App\Exceptions\TeleportError;
use App\Models\Device;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The teleport state machine. Ownership of a thread changes only in OwnershipMover.
 */
class Teleports
{
    public function __construct(
        private readonly TeleportThreadCreator $creator,
        private readonly BranchNamer $namer,
        private readonly ThreadAccess $access,
        private readonly TeleportDownBegin $downBegin,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{teleport: Teleport, resumed: bool}
     */
    public function beginDown(Device $device, array $validated): array
    {
        return $this->downBegin->begin($device, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{teleport: Teleport, thread: Thread, resumed: bool, isNew: bool, repos: list<array<string, mixed>>}
     */
    public function beginUp(Device $device, array $validated): array
    {
        $lock = Cache::lock('teleport-begin:' . $validated['session_id'], 15);

        return $lock->block(10, fn (): array => DB::transaction(fn (): array => $this->beginUpLocked($device, $validated)));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{teleport: Teleport, thread: Thread, resumed: bool, isNew: bool, repos: list<array<string, mixed>>}
     */
    private function beginUpLocked(Device $device, array $validated): array
    {
        $repositories = $this->resolveRepositories($validated['repos']);

        $existing = $this->threadForSession($validated['session_id']);

        if ($existing !== null && ! $this->access->canSee($device->user, $existing)) {
            throw new TeleportError('thread_not_found', 'That thread does not exist.', 404);
        }

        $thread = $existing === null || $existing->status === ThreadStatus::Closed ? null : Thread::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($existing->id);

        if ($thread !== null) {
            $open = $thread->openTeleport();

            if ($open !== null) {
                if ($open->device_id === $device->id && $open->direction === TeleportDirection::Up && $open->session_id === $validated['session_id']) {
                    return $this->resumedResult($open, $thread, $validated, $repositories);
                }

                throw $this->inProgress($open);
            }

            $this->assertDeviceMayUp($thread, $device, $validated, $repositories[0]);
        } else {
            $this->assertNoOpenTeleportForSession($device, $validated['session_id']);
        }

        $isNew = $thread === null;
        $thread ??= $this->creator->create($device, $validated, $repositories, $existing);

        if (! $isNew) {
            $this->creator->syncRepositories($thread, $validated['repos'], $repositories);
        }

        $teleport = Teleport::query()->create([
            'thread_id' => $thread->id,
            'device_id' => $device->id,
            'user_id' => $device->user_id,
            'direction' => TeleportDirection::Up,
            'state' => TeleportState::Open,
            'session_id' => $validated['session_id'],
            'payload' => ['repos' => $validated['repos'], 'missing_directories' => $validated['missing_directories'] ?? [], 'is_new' => $isNew],
            'last_call_at' => now(),
        ]);

        if (! $isNew && ! ThreadSession::query()->where(['thread_id' => $thread->id, 'session_id' => $validated['session_id']])->exists()) {
            ThreadSession::query()->create(['thread_id' => $thread->id, 'session_id' => $validated['session_id'], 'kind' => ThreadSessionKind::Main]);
        }

        return [
            'teleport' => $teleport,
            'thread' => $thread->refresh(),
            'resumed' => false,
            'isNew' => $isNew,
            'repos' => $this->repoPlan($thread, $validated['repos'], $repositories, $isNew),
        ];
    }

    public function get(Device $device, string $id): Teleport
    {
        $teleport = Teleport::query()->whereKey($id)->where('device_id', $device->id)->first();

        if ($teleport === null) {
            throw new TeleportError('teleport_not_found', 'That teleport does not exist.', 404);
        }

        if (! $teleport->state->isTerminal() && $teleport->expires_at !== null && $teleport->expires_at->isPast()) {
            $this->expire($teleport);
        }

        return $teleport->refresh();
    }

    public function cancel(Device $device, Teleport $teleport, string $reason, ?string $detail): Teleport
    {
        if ($teleport->state === TeleportState::Completed) {
            throw new TeleportError('teleport_completed', 'This teleport already completed.');
        }

        if ($teleport->state->isTerminal()) {
            return $teleport;
        }

        $this->close($teleport, TeleportState::Cancelled, $reason, $detail);

        return $teleport->refresh();
    }

    public function fail(Teleport $teleport, string $code, string $message): void
    {
        if (! $teleport->state->isTerminal()) {
            $this->close($teleport, TeleportState::Failed, $code, $message);
        }
    }

    public function cancelAllForDevice(Device $device): int
    {
        $teleports = Teleport::query()->where('device_id', $device->id)->whereIn('state', TeleportState::nonTerminal())->get();

        foreach ($teleports as $teleport) {
            $this->close($teleport, TeleportState::Cancelled, 'device_revoked', 'The device was revoked.');
        }

        return $teleports->count();
    }

    public function expireStale(): int
    {
        $teleports = Teleport::query()->whereIn('state', TeleportState::nonTerminal())->where('expires_at', '<=', now())->get();

        foreach ($teleports as $teleport) {
            $this->expire($teleport);
        }

        return $teleports->count();
    }

    private function expire(Teleport $teleport): void
    {
        $this->close($teleport, TeleportState::Expired, 'expired', 'No activity for ' . config('yak.teleport.expiry_minutes') . ' minutes.');
    }

    /**
     * Ends a teleport and clears the web marks; it never writes owner fields.
     */
    private function close(Teleport $teleport, TeleportState $state, string $code, ?string $message): void
    {
        DB::transaction(function () use ($teleport, $state, $code, $message): void {
            $teleport->forceFill(['state' => $state, 'failure_code' => $code, 'failure_message' => $message === null ? null : $this->redact($message)])->save();

            Thread::query()->withoutGlobalScopes()->whereKey($teleport->thread_id)->update([
                'waiting_for_computer_at' => null,
                'teleport_requested_running_turn' => null,
            ]);
        });
    }

    /**
     * Failure text may come from a device or a git error, so tokens and credentials in URLs never reach the row.
     */
    private function redact(string $message): string
    {
        $message = (string) preg_replace('#(://)[^/\s@]+@#', '$1[redacted]@', $message);

        return (string) preg_replace('/\b(yakdev_|gh[pousr]_|github_pat_)[A-Za-z0-9_]+/', '[redacted]', $message);
    }

    /**
     * @param  list<array<string, mixed>>  $requested
     * @return array<int, Repository>
     */
    private function resolveRepositories(array $requested): array
    {
        $repositories = [];
        $seenIds = [];
        $seenFolders = [];

        foreach ($requested as $index => $entry) {
            $name = strtolower((string) $entry['repo']);
            $repository = Repository::query()->whereRaw('LOWER(github_full_name) = ?', [$name])->first()
                ?? Repository::query()->whereRaw('LOWER(slug) = ?', [$name])->first();

            if ($repository === null) {
                throw new TeleportError('repo_not_on_yak', "{$entry['repo']} is not set up on Yak.", 422, ['repo' => $entry['repo']]);
            }

            $folder = strtolower(basename($repository->slug));

            if (in_array($repository->id, $seenIds, true) || in_array($folder, $seenFolders, true)) {
                throw new TeleportError('validation_failed', "{$entry['repo']} is listed twice or shares a folder name with another directory.", 422, ['repo' => $entry['repo']]);
            }

            $seenIds[] = $repository->id;
            $seenFolders[] = $folder;
            $repositories[$index] = $repository;
        }

        return $repositories;
    }

    private function threadForSession(string $sessionId): ?Thread
    {
        $threadId = ThreadSession::query()->where('session_id', $sessionId)->max('thread_id');

        return $threadId === null ? null : Thread::query()->withoutGlobalScopes()->whereKey($threadId)->first();
    }

    private function assertNoOpenTeleportForSession(Device $device, string $sessionId): void
    {
        $open = Teleport::query()->where('session_id', $sessionId)->whereIn('state', TeleportState::nonTerminal())->latest('id')->first();

        if ($open !== null) {
            throw $this->inProgress($open);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertDeviceMayUp(Thread $thread, Device $device, array $validated, Repository $primaryRepository): void
    {
        if ($thread->owner_side === ThreadOwnerSide::Yak) {
            throw new TeleportError('thread_owned_by_yak', 'This thread is on Yak. Run yak teleport ' . $thread->id . ' to bring it down first.', 409, ['thread_id' => $thread->id]);
        }

        if ($thread->owner_device_id !== $device->id) {
            $owner = Device::query()->find($thread->owner_device_id);

            throw new TeleportError('owned_by_other_device', 'This thread is on another of your computers.', 409, ['device_name' => $owner?->name]);
        }

        $primary = $validated['repos'][0];

        if ($thread->branch !== null && ! $this->isOnDefaultBranch($primary, $primaryRepository) && ($primary['branch'] ?? null) !== $thread->branch) {
            throw new TeleportError('branch_mismatch', "This thread works on {$thread->branch}, but the checkout is on " . ($primary['branch'] ?? 'no branch') . '.', 409, [
                'thread_branch' => $thread->branch,
                'current_branch' => $primary['branch'] ?? null,
            ]);
        }
    }

    /**
     * The client flag is a hint; the server also compares the branch with the repository's default branch.
     *
     * @param  array<string, mixed>  $entry
     */
    private function isOnDefaultBranch(array $entry, Repository $repository): bool
    {
        return (bool) ($entry['on_default_branch'] ?? false) || ($entry['branch'] ?? null) === $repository->default_branch;
    }

    private function inProgress(Teleport $open): TeleportError
    {
        return new TeleportError('teleport_in_progress', 'Another teleport is already running for this thread.', 409, [
            'teleport_id' => $open->id,
            'direction' => $open->direction->value,
            'device_name' => $open->device?->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, Repository>  $repositories
     * @return array{teleport: Teleport, thread: Thread, resumed: bool, isNew: bool, repos: list<array<string, mixed>>}
     */
    private function resumedResult(Teleport $open, Thread $thread, array $validated, array $repositories): array
    {
        $open->touchCall();

        return [
            'teleport' => $open,
            'thread' => $thread,
            'resumed' => true,
            'isNew' => (bool) ($open->payload['is_new'] ?? false),
            'repos' => $this->repoPlan($thread, $validated['repos'], $repositories, false),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $requested
     * @param  array<int, Repository>  $repositories
     * @return list<array<string, mixed>>
     */
    private function repoPlan(Thread $thread, array $requested, array $repositories, bool $isNew): array
    {
        $plan = [];
        $slug = $thread->branch !== null ? null : $this->namer->slug($thread->name);

        foreach ($requested as $index => $entry) {
            $role = RepositoryRole::from($entry['role']);
            $repository = $repositories[$index];
            $isContext = $role === RepositoryRole::Context;
            $createBranch = ! $isContext && $this->isOnDefaultBranch($entry, $repository);
            $branch = match (true) {
                $isContext => null,
                $createBranch => $thread->branch ?? $this->namer->branchFor((string) $slug),
                default => $entry['branch'] ?? null,
            };

            $plan[] = [
                'repo' => $entry['repo'],
                'role' => $role->value,
                'branch' => $branch,
                'create_branch' => $createBranch,
                'clone_url' => $repository->git_url,
                'sandbox_path' => $role === RepositoryRole::Primary ? '/workspace' : '/extras/' . basename($repository->slug),
            ];
        }

        return $plan;
    }
}
