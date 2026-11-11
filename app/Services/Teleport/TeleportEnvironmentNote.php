<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\SessionStore\SessionStore;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The sections of the environment note written at a teleport: what changed between the two machines.
 */
class TeleportEnvironmentNote
{
    private const TOUCHING_TOOLS = ['Read', 'Write', 'Edit', 'Glob', 'Grep'];

    public function __construct(
        private readonly SandboxManager $sandboxes,
        private readonly SessionStore $store,
    ) {}

    /**
     * The note for the sandbox after teleport up.
     *
     * @return array<string, string|list<string>>
     */
    public function forUp(Thread $thread, Teleport $teleport): array
    {
        $workspace = $this->workspace();
        $sections = [];
        $device = $teleport->device;

        if ($device !== null && filled($device->os)) {
            $sections['Machine switch'] = "The conversation moved from {$device->os} {$device->arch} to this Linux sandbox. Commands that behave differently per operating system or architecture may need adjusting.";
        }

        if (filled($thread->local_repo_path)) {
            $sections['Paths'] = "The project lives at {$workspace}. {$thread->local_repo_path} is a symlink to it, so paths from the person's computer resolve here.";
        }

        $refreshRan = $teleport->payload['refresh_ran'] ?? [];

        if ($refreshRan !== []) {
            $sections['Refresh commands that ran'] = array_values($refreshRan);
        }

        $missingFiles = $this->touchedButMissing($thread, (string) $teleport->session_id);

        if ($missingFiles !== []) {
            $sections['Files the conversation used that are not here'] = $missingFiles;
        }

        $missing = $teleport->payload['missing_directories'] ?? [];

        if ($missing !== []) {
            $sections['Directories that are not here'] = array_values($missing);
        }

        $extras = $this->upExtras($thread, $teleport);

        if ($extras !== []) {
            $sections['Extra repositories'] = $extras;
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function upExtras(Thread $thread, Teleport $teleport): array
    {
        $report = $teleport->payload['sandbox_repos'] ?? [];

        return array_values($thread->repositories()->where('role', '!=', RepositoryRole::Primary->value)->orderBy('repo')->get()
            ->map(function (ThreadRepository $repository) use ($teleport, $report): string {
                $line = "{$repository->repo}: {$repository->sandbox_path}";

                if ($repository->role === RepositoryRole::Context) {
                    $line .= ' (context, default branch; local edits were not uploaded)';

                    return ($report[$repository->repo]['status'] ?? null) === 'failed' ? $line . '; the clone failed, so it is not available here' : $line;
                }

                $localPath = $teleport->localPathOf($repository->repo);

                return $line . ' (changes)' . ($localPath === null ? '' : ", your path {$localPath} links to it");
            })
            ->all());
    }

    /**
     * The note for the local machine after teleport down.
     *
     * @return array<string, string|list<string>>
     */
    public function forDown(Thread $thread, Teleport $teleport): array
    {
        $workspace = $this->workspace();
        $localPath = $teleport->payload['local_path'] ?? $thread->local_repo_path;
        $sections = [
            'Machine switch' => 'The conversation moved from a Linux sandbox to ' . trim(($teleport->payload['os'] ?? 'your computer') . ' ' . ($teleport->payload['arch'] ?? '')) . '. Paths and installed tools from earlier in the conversation may differ.',
        ];

        if (filled($localPath)) {
            $sections['Paths'] = "Earlier messages mention {$workspace}. That is {$localPath} here.";
        }

        $commandsByRepo = [];

        foreach ($teleport->manifest['repos'] ?? [] as $entry) {
            $commandsByRepo[(string) $entry['repo']] = array_column($entry['refresh'] ?? [], 'command');
        }

        $extras = [];

        foreach ($this->extrasOfRole($thread, RepositoryRole::Changes) as $repository) {
            $commands = $commandsByRepo[$repository->repo] ?? [];
            $extras[] = "{$repository->repo} at {$repository->sandbox_path}" . ($commands === [] ? '' : ' (refresh: ' . implode(', ', $commands) . ')');
        }

        if ($extras !== []) {
            $sections['Extra repositories'] = $extras;
        }

        $context = $this->extrasOfRole($thread, RepositoryRole::Context)->pluck('repo')->all();

        if ($context !== []) {
            $sections['Context repositories'] = implode(', ', $context) . ' were not brought down. They stay in the sandbox only.';
        }

        return $sections;
    }

    /**
     * @return Collection<int, ThreadRepository>
     */
    private function extrasOfRole(Thread $thread, RepositoryRole $role): Collection
    {
        return $thread->repositories()->where('role', $role->value)->get();
    }

    /**
     * Paths the conversation read or wrote that git ignores, so the push did not carry them.
     *
     * @return list<string>
     */
    private function touchedButMissing(Thread $thread, string $sessionId): array
    {
        $paths = $this->touchedPaths($thread, $sessionId);

        if ($paths === []) {
            return [];
        }

        $result = $this->sandboxes->exec($thread, ['git', '-C', $this->workspace(), 'check-ignore', '--', ...$paths]);
        $ignored = array_filter(array_map('trim', explode("\n", $result->output())));

        return array_values(array_intersect($paths, $ignored));
    }

    /**
     * Workspace-relative paths named by Read, Write, Edit, Glob and Grep calls. Bash is not parsed.
     *
     * @return list<string>
     */
    private function touchedPaths(Thread $thread, string $sessionId): array
    {
        try {
            $raw = $this->store->read($thread, $sessionId, $sessionId . '.jsonl');
        } catch (Throwable) {
            return [];
        }

        $found = [];

        foreach (explode("\n", $raw) as $line) {
            $entry = json_decode($line, true);
            $content = is_array($entry) ? ($entry['message']['content'] ?? null) : null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (! is_array($block) || ($block['type'] ?? null) !== 'tool_use' || ! in_array($block['name'] ?? null, self::TOUCHING_TOOLS, true)) {
                    continue;
                }

                $path = $block['input']['file_path'] ?? $block['input']['path'] ?? null;
                $relative = is_string($path) ? $this->relativeToWorkspace($thread, $path) : null;

                if ($relative !== null) {
                    $found[$relative] = true;
                }
            }
        }

        return array_slice(array_keys($found), 0, 200);
    }

    private function relativeToWorkspace(Thread $thread, string $path): ?string
    {
        foreach (array_filter([$this->workspace(), $thread->local_repo_path]) as $root) {
            $prefix = rtrim((string) $root, '/') . '/';

            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return null;
    }

    private function workspace(): string
    {
        return (string) config('yak.sandbox.workspace_path', '/workspace');
    }
}
