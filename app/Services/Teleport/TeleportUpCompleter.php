<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Enums\TeleportState;
use App\Exceptions\TeleportError;
use App\Jobs\PrepareTeleportUpJob;
use App\Models\Device;
use App\Models\Repository;
use App\Models\SessionFile;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Teleport up, last step: verify the push and the session, prepare the sandbox, move ownership.
 */
class TeleportUpCompleter
{
    public function __construct(
        private readonly BranchHeadVerifier $heads,
        private readonly ClaudeAccountResolver $accounts,
        private readonly OwnershipMover $mover,
        private readonly HeldEventDelivery $held,
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
            case TeleportState::Preparing:
                return $this->respond($teleport);
            case TeleportState::Failed:
                throw new TeleportError('teleport_failed', (string) $teleport->failure_message, 409, ['code' => $teleport->failure_code, 'message' => $teleport->failure_message]);
            case TeleportState::Cancelled:
                throw new TeleportError('teleport_cancelled', 'This teleport was cancelled.');
            case TeleportState::Expired:
                throw new TeleportError('teleport_expired', 'This teleport expired. Start it again.', 409, ['minutes' => (int) config('yak.teleport.expiry_minutes')]);
            default:
                break;
        }

        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);
        $heads = $this->verifyPush($thread, $validated['repos']);
        $this->verifySession($thread, (string) $teleport->session_id);

        if (! $this->claim($teleport, $thread, $validated, $heads)) {
            return $this->respond($teleport->refresh());
        }

        dispatch(new PrepareTeleportUpJob($teleport->id));

        $deadline = now()->addSeconds((int) config('yak.teleport.complete_wait_seconds'));

        while (now()->lt($deadline)) {
            $teleport->refresh();

            if ($teleport->state !== TeleportState::Preparing) {
                break;
            }

            usleep(250_000);
        }

        return $this->respond($teleport->refresh());
    }

    /**
     * Steps e and f: deliver held events and move ownership, then start the first turn.
     */
    public function finish(Teleport $teleport, string $sandboxState): void
    {
        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);
        $deliverHeld = (bool) ($teleport->payload['deliver_held_events'] ?? true);
        $heldMessage = $deliverHeld ? $this->held->firstMessageFor($thread) : null;
        $prompt = collect([$heldMessage, $teleport->message])->filter(fn (?string $part): bool => filled($part))->implode("\n\n");

        $this->mover->toYak($thread, $teleport);

        $turnStarted = false;

        if ($prompt !== '') {
            $turnStarted = $this->startTurn($thread, $teleport, $prompt);
        }

        $delivered = $turnStarted && $heldMessage !== null ? $this->held->markDelivered($thread) : 0;

        $teleport->forceFill(['payload' => [...($teleport->payload ?? []), 'result' => [
            'sandbox_state' => $sandboxState,
            'turn_started' => $turnStarted,
            'held_events_delivered' => $delivered,
        ], 'uploaded_files' => $this->uploadedFiles($thread, (string) $teleport->session_id)]])->save();
    }

    /**
     * @return list<array{rel_path: string, length: int, sha256: ?string}>
     */
    private function uploadedFiles(Thread $thread, string $sessionId): array
    {
        return array_values(SessionFile::query()->where(['thread_id' => $thread->id, 'session_id' => $sessionId])->orderBy('rel_path')->get()
            ->map(fn (SessionFile $file): array => ['rel_path' => $file->rel_path, 'length' => (int) $file->length, 'sha256' => $file->sha256])
            ->all());
    }

    /**
     * Only context repos may omit branch and head_sha; every other repo must be visible on GitHub.
     *
     * @param  list<array<string, mixed>>  $repos
     * @return array<string, string>
     */
    private function verifyPush(Thread $thread, array $repos): array
    {
        $heads = [];
        $contextRepos = ThreadRepository::query()
            ->where('thread_id', $thread->id)
            ->where('role', RepositoryRole::Context)
            ->pluck('repo')
            ->map(fn (string $repo): string => strtolower($repo))
            ->all();

        foreach ($repos as $entry) {
            if (blank($entry['branch'] ?? null) || blank($entry['head_sha'] ?? null)) {
                if (in_array(strtolower((string) $entry['repo']), $contextRepos, true)) {
                    continue;
                }

                throw new TeleportError('push_not_visible', "{$entry['repo']} needs a pushed branch and head_sha.", 409, ['repo' => $entry['repo']]);
            }

            $name = strtolower((string) $entry['repo']);
            $repository = Repository::query()->whereRaw('LOWER(github_full_name) = ?', [$name])->first()
                ?? Repository::query()->whereRaw('LOWER(slug) = ?', [$name])->first();

            if ($repository !== null && $repository->default_branch === $entry['branch']) {
                throw new TeleportError('default_branch_not_allowed', 'Teleport never pushes the default branch.', 409, ['repo' => $entry['repo'], 'branch' => $entry['branch']]);
            }

            if ($this->heads->headOf($entry['repo'], $entry['branch']) !== $entry['head_sha']) {
                throw new TeleportError('push_not_visible', "{$entry['branch']} on GitHub is not at {$entry['head_sha']} yet.", 409, ['repo' => $entry['repo'], 'branch' => $entry['branch']]);
            }

            $heads[$entry['repo']] = $entry['head_sha'];
        }

        return $heads;
    }

    private function verifySession(Thread $thread, string $sessionId): void
    {
        $file = SessionFile::query()->where(['thread_id' => $thread->id, 'session_id' => $sessionId, 'rel_path' => $sessionId . '.jsonl'])->first();

        if ($file === null || $file->sha256 === null || $file->sha256 !== $file->checkpoint_sha256) {
            throw new TeleportError('session_incomplete', 'The session was not uploaded completely.');
        }
    }

    /**
     * Fixes the account and branch, then moves the teleport to preparing. False when another call won.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, string>  $heads
     */
    private function claim(Teleport $teleport, Thread $thread, array $validated, array $heads): bool
    {
        return DB::transaction(function () use ($teleport, $thread, $validated, $heads): bool {
            $locked = Teleport::query()->lockForUpdate()->findOrFail($teleport->id);

            if ($locked->state !== TeleportState::Open) {
                return false;
            }

            $lockedThread = Thread::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($thread->id);

            if ($lockedThread->claude_account_id === null) {
                $resolved = $this->accounts->forNewThread($teleport->user, $lockedThread->repo, null, null);
                $account = $resolved->account ?? throw new TeleportError('no_claude_account', 'No Claude account is available for this thread.', 409);
                $lockedThread->forceFill(['claude_account_id' => $account->id, 'model' => $resolved->model]);
            }

            $primary = $validated['repos'][0];
            $lockedThread->branch ??= $primary['branch'] ?? null;
            $lockedThread->save();

            foreach ($validated['repos'] as $entry) {
                ThreadRepository::query()
                    ->where('thread_id', $lockedThread->id)
                    ->whereRaw('LOWER(repo) = ?', [strtolower((string) $entry['repo'])])
                    ->update(['branch' => $entry['branch'] ?? null]);
            }

            $locked->forceFill([
                'state' => TeleportState::Preparing,
                'branch_heads' => $heads,
                'message' => $validated['message'] ?? null,
                'payload' => [...($locked->payload ?? []), 'deliver_held_events' => (bool) ($validated['deliver_held_events'] ?? true), 'prepare_started_at' => now()->toIso8601String()],
            ])->save();
            $teleport->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    private function startTurn(Thread $thread, Teleport $teleport, string $prompt): bool
    {
        try {
            $task = YakTask::query()->where('thread_id', $thread->id)->orderBy('id')->firstOrFail();
            app(ThreadTurns::class)->start($task, $prompt, $teleport->user, null);

            return true;
        } catch (Throwable $exception) {
            Log::warning('The first turn after teleport up did not start', ['thread' => $thread->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @return list<array{repo: string, role: string, status: string, reason: ?string}>
     */
    private function sandboxRepos(Teleport $teleport): array
    {
        $report = $teleport->payload['sandbox_repos'] ?? [];

        if ($report === []) {
            return [];
        }

        $roles = ThreadRepository::query()->where('thread_id', $teleport->thread_id)->get()->keyBy(fn (ThreadRepository $repository): string => strtolower($repository->repo));
        $repos = [];

        foreach ($teleport->payload['repos'] ?? [] as $requested) {
            $repository = $roles->get(strtolower((string) $requested['repo']));
            $entry = $repository?->role === RepositoryRole::Primary ? ['status' => 'ready', 'reason' => null] : ($report[$repository?->repo] ?? null);

            if ($repository !== null && $entry !== null) {
                $repos[] = ['repo' => $repository->repo, 'role' => $repository->role->value, 'status' => $entry['status'], 'reason' => $entry['reason']];
            }
        }

        return $repos;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function respond(Teleport $teleport): array
    {
        if ($teleport->state === TeleportState::Failed) {
            throw new TeleportError('teleport_failed', (string) $teleport->failure_message, 409, ['code' => $teleport->failure_code, 'message' => $teleport->failure_message]);
        }

        if ($teleport->state !== TeleportState::Completed) {
            $body = ['teleport' => $teleport->toApi()];

            if (($teleport->payload['waiting_for_slot'] ?? false) === true) {
                $body['message'] = 'Yak has no free sandbox right now. Waiting for one before the thread moves.';
            }

            return ['status' => 202, 'body' => $body];
        }

        $result = $teleport->payload['result'] ?? [];
        $sandboxState = $result['sandbox_state'] ?? 'ready';

        return ['status' => 200, 'body' => [
            'teleport' => $teleport->toApi(),
            'ownership' => 'yak',
            'sandbox' => array_filter([
                'state' => $sandboxState,
                'repos' => $this->sandboxRepos($teleport),
            ], fn (mixed $value, string $key): bool => $key !== 'repos' || $value !== [], ARRAY_FILTER_USE_BOTH),
            'turn_started' => (bool) ($result['turn_started'] ?? false),
            'held_events_delivered' => (int) ($result['held_events_delivered'] ?? 0),
            'marker' => [
                'thread_id' => $teleport->thread_id,
                'teleport_id' => $teleport->id,
                'owner' => 'yak',
                'thread_url' => route('threads.show', ['thread' => Thread::publicIdForKey($teleport->thread_id)]),
            ],
        ]];
    }
}
