<?php

namespace App\Services\Threads;

use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use App\Services\Threads\Contracts\SandboxManager;
use Throwable;

/**
 * Gitignored files a conversation touched, so a push or a rebuilt sandbox cannot carry them.
 */
class TouchedIgnoredFiles
{
    private const TOUCHING_TOOLS = ['Read', 'Write', 'Edit', 'Glob', 'Grep'];

    public function __construct(
        private readonly SandboxManager $sandboxes,
        private readonly SessionStore $store,
    ) {}

    /**
     * Paths the conversation read or wrote that git ignores, so the push did not carry them.
     *
     * @return list<string>
     */
    public function missing(Thread $thread, string $sessionId): array
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
