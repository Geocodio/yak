<?php

namespace App\Services\Threads\Files;

use App\DataTransferObjects\Files\FileContent;
use App\DataTransferObjects\Files\FileEntry;
use App\Enums\FileScope;
use App\Models\Thread;
use App\Services\Threads\ThreadShell;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads folders and files in a thread's sandbox through fixed shell snippets.
 * Every path reaches the shell through escapeshellarg.
 */
class SandboxFiles
{
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
        $absolute = SandboxPath::resolveDirectory($thread, $scope, $directory);
        $guard = $this->guard($thread, $scope, $absolute);

        $result = ThreadShell::for($thread)->run('', sprintf(
            "%s find %s -mindepth 1 -maxdepth 1 -printf '%%y\\t%%s\\t%%f\\0'",
            $guard,
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

        return array_slice($entries, 0, (int) config('yak.files.tree_limit'));
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

        $result = ThreadShell::for($thread)->run('', sprintf(
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
