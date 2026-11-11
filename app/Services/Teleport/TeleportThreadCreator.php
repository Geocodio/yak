<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Enums\TaskMode;
use App\Enums\ThreadKind;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadVisibility;
use App\Models\Device;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadSession;
use App\Models\YakTask;
use Illuminate\Support\Str;

/**
 * Creates the private, device-owned thread a first teleport up lands on.
 */
class TeleportThreadCreator
{
    public function __construct(private readonly BranchNamer $namer) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, Repository>  $repositories  keyed like $validated['repos']
     */
    public function create(Device $device, array $validated, array $repositories, ?Thread $continuedFrom = null): Thread
    {
        $user = $device->user ?? throw new \LogicException('A device always has a user.');
        $title = trim((string) ($validated['title_hint'] ?? ''));
        $primary = $repositories[0];

        $thread = Thread::query()->create([
            'name' => $this->uniqueName($this->namer->slug($title)),
            'title' => mb_substr($title === '' ? 'Teleported session' : $title, 0, 200),
            'repo' => $primary->slug,
            'visibility' => ThreadVisibility::Private,
            'owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'continued_from_thread_id' => $continuedFrom?->id,
            'kind' => ThreadKind::General,
            'owner_side' => ThreadOwnerSide::Device,
            'owner_device_id' => $device->id,
            'owner_changed_at' => now(),
            'git_author_name' => $validated['git_name'] ?? null,
            'git_author_email' => $validated['git_email'] ?? null,
            'local_repo_path' => $validated['local_path'] ?? null,
        ]);

        ThreadSession::query()->create([
            'thread_id' => $thread->id,
            'session_id' => $validated['session_id'],
            'kind' => ThreadSessionKind::Main,
        ]);

        YakTask::query()->create([
            'source' => YakTask::SOURCE_TELEPORT,
            'repo' => $primary->slug,
            'external_id' => 'TELEPORT-' . Str::upper(Str::random(8)),
            'description' => '',
            'mode' => TaskMode::Fix,
            'thread_id' => $thread->id,
            'author_name' => $user->name,
            'started_by_user_id' => $user->id,
        ]);

        $this->syncRepositories($thread, $validated['repos'], $repositories);

        return $thread;
    }

    /**
     * @param  list<array<string, mixed>>  $requested
     * @param  array<int, Repository>  $repositories
     */
    public function syncRepositories(Thread $thread, array $requested, array $repositories): void
    {
        $takenPaths = [];

        foreach ($requested as $index => $entry) {
            $role = RepositoryRole::from($entry['role']);
            $slug = $repositories[$index]->slug;
            $takenPaths[] = $sandboxPath = ThreadRepository::sandboxPathFor($slug, $role, $takenPaths);

            ThreadRepository::query()->updateOrCreate(
                ['thread_id' => $thread->id, 'repo' => $slug],
                ['role' => $role, 'sandbox_path' => $sandboxPath],
            );
        }
    }

    private function uniqueName(string $slug): string
    {
        $name = $slug;

        for ($suffix = 2; Thread::withoutGlobalScopes()->where('name', $name)->exists(); $suffix++) {
            $name = "{$slug}-{$suffix}";
        }

        return $name;
    }
}
