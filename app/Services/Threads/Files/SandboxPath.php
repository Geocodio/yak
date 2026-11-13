<?php

namespace App\Services\Threads\Files;

use App\Enums\FileScope;
use App\Models\Thread;

/**
 * Maps a requested path to an absolute sandbox path inside the scope root.
 * The path is refused before anything runs when it is empty, carries a NUL
 * byte, has a `..` segment or leaves the root. Symlinks are checked in the
 * sandbox by SandboxFiles, which is the only place that can see them.
 */
class SandboxPath
{
    public static function root(Thread $thread, FileScope $scope): string
    {
        $workspace = rtrim((string) config('yak.sandbox.workspace_path'), '/');

        if ($scope === FileScope::Sandbox) {
            return '/';
        }

        // The primary repository is cloned into its recorded sandbox_path (the workspace itself).
        return rtrim((string) ($thread->primaryRepository()->value('sandbox_path') ?? $workspace), '/') ?: $workspace;
    }

    public static function resolve(Thread $thread, FileScope $scope, string $path): string
    {
        if ($path === '') {
            throw new InvalidFilePath('A file path is required.');
        }

        if (str_contains($path, "\0")) {
            throw new InvalidFilePath('The path contains a NUL byte.');
        }

        $root = self::root($thread, $scope);
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new InvalidFilePath('The path may not contain "..".');
            }

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        $joined = '/' . implode('/', $segments);
        $absolute = $isAbsolute ? $joined : rtrim($root, '/') . $joined;

        if (! self::isWithin($absolute, $root)) {
            throw new InvalidFilePath('The path is outside the allowed folder.');
        }

        return $absolute;
    }

    /** Like resolve(), but an empty path means the scope root. */
    public static function resolveDirectory(Thread $thread, FileScope $scope, string $path): string
    {
        return $path === '' ? self::root($thread, $scope) : self::resolve($thread, $scope, $path);
    }

    public static function isWritable(string $absolute): bool
    {
        $workspace = rtrim((string) config('yak.sandbox.workspace_path'), '/');

        return self::isWithin($absolute, '/home/yak') || self::isWithin($absolute, $workspace);
    }

    /** The path as the browser shows it: relative to the repo root in repo scope, absolute otherwise. */
    public static function display(Thread $thread, FileScope $scope, string $absolute): string
    {
        if ($scope === FileScope::Sandbox) {
            return $absolute;
        }

        return ltrim(substr($absolute, strlen(rtrim(self::root($thread, $scope), '/'))), '/');
    }

    private static function isWithin(string $absolute, string $root): bool
    {
        $root = rtrim($root, '/');

        return $root === '' || $absolute === $root || str_starts_with($absolute, $root . '/');
    }
}
