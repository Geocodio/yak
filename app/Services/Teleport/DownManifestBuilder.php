<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Models\Repository;
use App\Models\SessionFile;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Services\SessionStore\SessionStore;
use App\Services\Threads\RefreshTriggers;

/**
 * The teleport-down manifest of contract section 8.
 */
class DownManifestBuilder
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly RefreshTriggers $refresh,
        private readonly TeleportRefreshMaps $refreshMaps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Teleport $teleport): array
    {
        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);
        $lastUp = $this->lastUp($thread, $teleport);

        return [
            'thread' => [
                'id' => $thread->id,
                'slug' => $thread->name,
                'title' => $thread->title,
                'visibility' => $thread->visibility->value,
                'claude_account' => ['name' => $thread->claudeAccount?->name],
            ],
            'repos' => $this->repos($thread, $teleport, $lastUp),
            'context_repos' => $thread->repositories->where('role', RepositoryRole::Context)->pluck('repo')->values()->all(),
            'last_up' => $lastUp,
            'session' => $this->session($thread),
            'memory' => $this->memory($thread, $teleport),
            'environment' => [
                'sandbox_os' => 'linux',
                'sandbox_arch' => (string) config('yak.sandbox.arch', 'x86_64'),
                'sandbox_workspace_path' => (string) config('yak.sandbox.workspace_path', '/workspace'),
                'extras_path_pattern' => '/extras/<repo>',
                'refresh' => collect($this->refresh->configFor($thread))
                    ->map(fn (string $command, string $glob): array => ['glob' => $glob, 'command' => $command])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * The sha256 of every memory file as it is now.
     *
     * @return array<string, string>
     */
    public function memorySnapshot(Thread $thread): array
    {
        return SessionFile::query()
            ->where(['thread_id' => $thread->id, 'session_id' => SessionStore::MEMORY_SESSION_ID])
            ->get()
            ->mapWithKeys(fn (SessionFile $file): array => [$file->rel_path => $file->sha256 ?? hash('sha256', $this->store->read($thread, SessionStore::MEMORY_SESSION_ID, $file->rel_path))])
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $lastUp
     * @return list<array<string, mixed>>
     */
    private function repos(Thread $thread, Teleport $teleport, ?array $lastUp): array
    {
        $repos = [];

        foreach ($thread->repositories as $repository) {
            if ($repository->role === RepositoryRole::Context || blank($repository->branch)) {
                continue;
            }

            $model = Repository::query()->whereRaw('LOWER(github_full_name) = ?', [strtolower($repository->repo)])->first();
            $head = $teleport->branch_heads[$repository->repo] ?? null;
            $failure = $teleport->payload['repo_failures'][$repository->repo] ?? null;
            $repos[] = [
                'repo' => $repository->repo,
                'role' => $repository->role->value,
                'clone_url' => $model?->git_url,
                'default_branch' => $model->default_branch ?? 'main',
                'branch' => $repository->branch,
                'head_sha' => $head,
                'branch_created_by_yak' => $lastUp === null || ($lastUp['branch'] ?? null) !== $repository->branch,
                'sandbox_path' => $repository->sandbox_path,
                'wip_commit_made' => (bool) ($teleport->payload['wip_commit_made'][$repository->repo] ?? false),
                'pushed' => $head !== null,
                'status' => $failure === null ? 'ready' : 'failed',
                'failure' => $failure,
                'refresh' => $failure === null ? $this->refreshMaps->forRepository($thread, $repository) : [],
            ];
        }

        return $repos;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastUp(Thread $thread, Teleport $current): ?array
    {
        $up = $thread->teleports()
            ->where('direction', TeleportDirection::Up)
            ->where('state', TeleportState::Completed)
            ->where('id', '!=', $current->id)
            ->latest('completed_at')
            ->first();

        if ($up === null) {
            return null;
        }

        $branch = $thread->primaryRepository->branch ?? $thread->branch;

        return [
            'teleport_id' => $up->id,
            'branch' => $branch,
            'head_sha' => $branch === null ? null : ($up->branch_heads[$thread->repo] ?? array_values($up->branch_heads ?? [])[0] ?? null),
            'files' => $up->payload['uploaded_files'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function session(Thread $thread): array
    {
        $active = $thread->activeSession()?->session_id;

        return [
            'session_id' => $active,
            'files' => $active === null ? [] : $this->store->files($thread, $active),
            'other_session_ids' => ThreadSession::query()
                ->where('thread_id', $thread->id)
                ->when($active !== null, fn ($query) => $query->where('session_id', '!=', $active))
                ->pluck('session_id')
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * Memory files that differ from the snapshot of the last completed teleport.
     *
     * @return list<array<string, mixed>>
     */
    private function memory(Thread $thread, Teleport $current): array
    {
        $previous = $thread->teleports()
            ->where('state', TeleportState::Completed)
            ->where('id', '!=', $current->id)
            ->whereNotNull('memory_snapshot')
            ->latest('completed_at')
            ->first()->memory_snapshot ?? [];
        $files = SessionFile::query()->where(['thread_id' => $thread->id, 'session_id' => SessionStore::MEMORY_SESSION_ID])->orderBy('rel_path')->get();
        $memory = [];

        foreach ($files as $file) {
            $sha = $file->sha256 ?? hash('sha256', $this->store->read($thread, SessionStore::MEMORY_SESSION_ID, $file->rel_path));

            if (($previous[$file->rel_path] ?? null) !== $sha) {
                $memory[] = ['rel_path' => $file->rel_path, 'length' => (int) $file->length, 'sha256' => $sha, 'changed_since_last_teleport' => true];
            }
        }

        return $memory;
    }
}
