<?php

namespace App\Services\Threads\Files;

use App\DataTransferObjects\Files\FileContent;
use App\DataTransferObjects\Files\FileEntry;
use App\DataTransferObjects\Files\FolderListing;
use App\Enums\FileScope;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads folders and files in a thread's sandbox through fixed shell snippets.
 * Every path reaches the shell through escapeshellarg.
 */
class SandboxFiles
{
    public const COMMAND_TIMEOUT_SECONDS = 20;

    public const SEARCH_LIMIT = 100;

    private const EXIT_OUTSIDE_ROOT = 3;

    private const EXIT_NOT_A_FILE = 4;

    private const BINARY_PROBE_BYTES = 8192;

    /** @var array<string, string> */
    private const LANGUAGES = [
        'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'jsx' => 'javascript',
        'ts' => 'typescript', 'mts' => 'typescript', 'cts' => 'typescript', 'tsx' => 'typescript',
        'json' => 'json', 'jsonc' => 'json', 'php' => 'php', 'py' => 'python', 'rb' => 'ruby', 'go' => 'go',
        'rs' => 'rust', 'java' => 'java', 'kt' => 'kotlin', 'cs' => 'csharp', 'c' => 'c', 'h' => 'c',
        'cpp' => 'cpp', 'cc' => 'cpp', 'hpp' => 'cpp', 'swift' => 'swift', 'css' => 'css', 'scss' => 'scss',
        'less' => 'less', 'html' => 'html', 'htm' => 'html', 'vue' => 'html', 'svelte' => 'html',
        'xml' => 'xml', 'svg' => 'xml', 'sql' => 'sql', 'yml' => 'yaml', 'yaml' => 'yaml', 'toml' => 'toml',
        'ini' => 'config', 'env' => 'config', 'conf' => 'config', 'sh' => 'shell', 'bash' => 'shell',
        'zsh' => 'shell', 'diff' => 'diff', 'patch' => 'diff', 'md' => 'markdown', 'lua' => 'lua',
    ];

    /**
     * One level of a folder: directories first, then files, each by name without regard to case.
     *
     * @return list<FileEntry>
     */
    public function tree(Thread $thread, FileScope $scope, string $directory): array
    {
        return $this->listFolder($thread, $scope, $directory)->entries;
    }

    /**
     * One level of a folder, cut at the tree limit. A missing folder, or a path that is a file, is not found.
     */
    public function listFolder(Thread $thread, FileScope $scope, string $directory): FolderListing
    {
        $absolute = SandboxPath::resolveDirectory($thread, $scope, $directory);
        $guard = $this->guard($thread, $scope, $absolute);

        $result = $this->exec($thread, sprintf(
            "%s[ -d %s ] || exit %d; find %s -mindepth 1 -maxdepth 1 -printf '%%y\\t%%s\\t%%f\\0'",
            $guard,
            escapeshellarg($absolute),
            self::EXIT_NOT_A_FILE,
            escapeshellarg($absolute),
        ));

        $this->failOnGuard($result->exitCode());

        $entries = [];

        foreach (explode("\0", $result->output()) as $record) {
            $parts = explode("\t", $record, 3);

            if (count($parts) !== 3) {
                continue;
            }

            [$type, $size, $name] = $parts;
            $child = rtrim($absolute, '/') . '/' . $name;
            $isDirectory = $type === 'd';

            $entries[] = new FileEntry(
                $name,
                SandboxPath::display($thread, $scope, $child),
                $isDirectory,
                SandboxPath::isWritable($child),
                $isDirectory ? null : (int) $size,
            );
        }

        usort($entries, fn (FileEntry $left, FileEntry $right): int => [! $left->isDirectory, mb_strtolower($left->name)]
            <=> [! $right->isDirectory, mb_strtolower($right->name)]);

        $limit = (int) config('yak.files.tree_limit');

        return new FolderListing(array_slice($entries, 0, $limit), count($entries) > $limit);
    }

    /**
     * Tracked and untracked, not ignored repository files whose path contains the query, case-insensitively.
     * Git must exit 0 (or 141 when a later stage closes the pipe), grep 0, 1 (no match) or 141; any other status exits 2.
     *
     * @return array{matches: list<array{name: string, path: string}>, truncated: bool}
     */
    public function search(Thread $thread, string $query): array
    {
        $root = SandboxPath::root($thread, FileScope::Repository);

        $result = $this->exec($thread, sprintf(
            '%s git -C %s ls-files -z --cached --others --exclude-standard | grep -z -i -F -e %s | head -z -n %d; codes=("${PIPESTATUS[@]}"); case ${codes[0]} in 0|141) ;; *) exit 2;; esac; case ${codes[1]} in 0|1|141) ;; *) exit 2;; esac',
            $this->guard($thread, FileScope::Repository, $root),
            escapeshellarg($root),
            escapeshellarg($query),
            self::SEARCH_LIMIT + 1,
        ));

        $this->failOnGuard($result->exitCode());

        if ($result->exitCode() !== 0) {
            throw new SandboxCommandFailed('A sandbox file command failed.');
        }

        $paths = array_values(array_filter(explode("\0", rtrim($result->output(), "\n")), fn (string $path): bool => $path !== ''));
        $matches = array_map(fn (string $path): array => ['name' => basename($path), 'path' => $path], array_slice($paths, 0, self::SEARCH_LIMIT));

        return ['matches' => $matches, 'truncated' => count($paths) > self::SEARCH_LIMIT];
    }

    public function read(Thread $thread, FileScope $scope, string $path): FileContent
    {
        $absolute = SandboxPath::resolve($thread, $scope, $path);
        $isRepository = $scope === FileScope::Repository;
        $root = SandboxPath::root($thread, $scope);

        return $this->fetch(
            $thread,
            $absolute,
            SandboxPath::display($thread, $scope, $absolute),
            $this->guard($thread, $scope, $absolute),
            $isRepository ? [$root, SandboxPath::display($thread, $scope, $absolute)] : null,
        );
    }

    public function readRaw(Thread $thread, string $absolutePath): FileContent
    {
        return $this->fetch($thread, $absolutePath, $absolutePath, '', null);
    }

    /**
     * @param  array{0: string, 1: string}|null  $git  repository root and the path relative to it
     */
    private function fetch(Thread $thread, string $absolute, string $displayPath, string $guard, ?array $git): FileContent
    {
        $maxBytes = (int) config('yak.files.max_edit_bytes');
        $path = escapeshellarg($absolute);

        $headScript = $git === null ? 'echo -' : sprintf(
            'if git -C %1$s cat-file -e %2$s 2>/dev/null; then git -C %1$s show %2$s | head -c %3$d | base64 -w0; echo; else echo -; fi',
            escapeshellarg($git[0]),
            escapeshellarg('HEAD:' . $git[1]),
            $maxBytes + 1,
        );

        $result = $this->exec($thread, sprintf(
            '%s [ -f %s ] || exit %d; stat -c %%s -- %s; head -c %d -- %s | base64 -w0; echo; %s',
            $guard,
            $path,
            self::EXIT_NOT_A_FILE,
            $path,
            $maxBytes + 1,
            $path,
            $headScript,
        ));

        $this->failOnGuard($result->exitCode());

        [$sizeLine, $bodyLine, $headLine] = [...explode("\n", $result->output()), '', '', ''];

        $bytes = (string) base64_decode(trim($bodyLine), true);
        $size = (int) trim($sizeLine);
        $isTooLarge = $size > $maxBytes;
        $isBinary = ! $isTooLarge && $this->isBinary($bytes);
        $isText = ! $isTooLarge && ! $isBinary;

        return new FileContent(
            $displayPath,
            hash('sha256', $bytes),
            $size,
            $isBinary,
            $isTooLarge,
            $isText ? $bytes : null,
            $isText ? $this->decodeHead(trim($headLine)) : null,
            $this->language($absolute),
        );
    }

    /** Runs one snippet in the sandbox and gives up after the file command bound, without naming the path. */
    private function exec(Thread $thread, string $script): ProcessResult
    {
        try {
            return app(SandboxManager::class)->exec($thread, ['bash', '-lc', $script], null, [], self::COMMAND_TIMEOUT_SECONDS);
        } catch (ProcessTimedOutException) {
            throw new SandboxCommandFailed('A sandbox file command timed out.');
        }
    }

    private function decodeHead(string $line): ?string
    {
        if ($line === '-') {
            return null;
        }

        $decoded = base64_decode($line, true);

        return $decoded === false || $this->isBinary($decoded) ? null : $decoded;
    }

    private function isBinary(string $bytes): bool
    {
        return str_contains(substr($bytes, 0, self::BINARY_PROBE_BYTES), "\0") || ! mb_check_encoding($bytes, 'UTF-8');
    }

    private function language(string $absolute): string
    {
        return self::LANGUAGES[strtolower(pathinfo($absolute, PATHINFO_EXTENSION))] ?? 'text';
    }

    /** Shell prefix that exits 3 when a symlink leads out of the repository root. */
    private function guard(Thread $thread, FileScope $scope, string $absolute): string
    {
        if ($scope === FileScope::Sandbox) {
            return '';
        }

        $root = escapeshellarg(SandboxPath::root($thread, $scope));

        return sprintf(
            'real=$(realpath -e -- %1$s) || exit %4$d; top=$(realpath -e -- %2$s) || exit %4$d; case "$real" in "$top"|"$top"/*) ;; *) exit %3$d;; esac;',
            escapeshellarg($absolute),
            $root,
            self::EXIT_OUTSIDE_ROOT,
            self::EXIT_NOT_A_FILE,
        );
    }

    private function failOnGuard(?int $exitCode): void
    {
        match ($exitCode) {
            self::EXIT_OUTSIDE_ROOT => throw new InvalidFilePath('The path is outside the allowed folder.'),
            self::EXIT_NOT_A_FILE => throw new NotFoundHttpException('Not a file.'),
            default => null,
        };
    }
}
