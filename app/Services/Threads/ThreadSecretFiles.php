<?php

namespace App\Services\Threads;

use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use App\Support\SecretFilePatterns;

/**
 * Names of secret-looking files a thread's transcripts opened or wrote, for
 * the Share dialog warning. Reads names from tool-use inputs only and never
 * returns file contents.
 */
class ThreadSecretFiles
{
    private const int MAX_TRANSCRIPT_BYTES = 20 * 1024 * 1024;

    private const array FILE_TOOLS = ['Read', 'Edit', 'Write', 'NotebookEdit'];

    public function __construct(private readonly SessionStore $store) {}

    /**
     * @return array{files: list<string>, memoryFolder: ?string, memoryFiles: list<string>}
     */
    public function for(Thread $thread): array
    {
        $paths = [];
        $remainingBytes = self::MAX_TRANSCRIPT_BYTES;

        foreach ($thread->sessions()->orderBy('id')->pluck('session_id') as $sessionId) {
            $transcript = $sessionId . '.jsonl';
            $length = $this->store->length($thread, $sessionId, $transcript);

            if ($length === 0 || $length > $remainingBytes) {
                continue;
            }

            $remainingBytes -= $length;

            try {
                $body = $this->store->read($thread, $sessionId, $transcript);
            } catch (\Throwable) {
                continue;
            }

            foreach ($this->toolPaths($body) as $path) {
                $paths[] = $path;
            }
        }

        $files = SecretFilePatterns::filter(array_values(array_unique($paths)));
        sort($files);

        $memoryFiles = array_values(array_map(
            fn (array $file): string => basename($file['rel_path']),
            $this->store->files($thread, SessionStore::MEMORY_SESSION_ID),
        ));

        return [
            'files' => $files,
            'memoryFolder' => $memoryFiles === [] ? null : 'memory/',
            'memoryFiles' => $memoryFiles,
        ];
    }

    /**
     * @return \Generator<int, string>
     */
    private function toolPaths(string $transcript): \Generator
    {
        foreach (explode("\n", $transcript) as $line) {
            $decoded = json_decode($line, true);
            $content = is_array($decoded) ? ($decoded['message']['content'] ?? null) : null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'tool_use' || ! in_array($item['name'] ?? null, self::FILE_TOOLS, true)) {
                    continue;
                }

                $path = $item['input']['file_path'] ?? $item['input']['notebook_path'] ?? null;

                if (is_string($path) && $path !== '') {
                    yield $this->resolve($path);
                }
            }
        }
    }

    /**
     * Collapses `.`, `..`, doubled and trailing slashes, keeping a leading slash.
     */
    private function resolve(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $segments);
    }
}
