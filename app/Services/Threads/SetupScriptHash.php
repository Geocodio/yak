<?php

namespace App\Services\Threads;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\SandboxManager;

/**
 * Fingerprint of a repository's .yak/setup.sh, stored with the sandbox
 * template so a changed script rebuilds the template.
 */
class SetupScriptHash
{
    private const SCRIPT_PATH = '.yak/setup.sh';

    public static function forContents(?string $contents): ?string
    {
        return $contents === null || $contents === '' ? null : hash('sha256', $contents);
    }

    /** Reads the script from a thread's sandbox, or from a container by name. */
    public function forSandbox(Thread|string $containerOrThread): ?string
    {
        $result = $containerOrThread instanceof Thread
            ? app(SandboxManager::class)->exec($containerOrThread, ['cat', self::SCRIPT_PATH])
            : app(IncusSandboxManager::class)->run($containerOrThread, 'cat ' . escapeshellarg((string) config('yak.sandbox.workspace_path', '/workspace') . '/' . self::SCRIPT_PATH), timeout: 30);

        return $result->successful() ? self::forContents($result->output()) : null;
    }

    /** Null when the default branch has no setup.sh, or GitHub is not configured. */
    public function forDefaultBranch(Repository $repository): ?string
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            return null;
        }

        return self::forContents(app(AppService::class)->getFileContents(
            $installationId,
            $repository->github_full_name,
            self::SCRIPT_PATH,
            $repository->default_branch,
        ));
    }
}
