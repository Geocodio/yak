<?php

namespace App\Services\Threads\Repos;

use App\Channels\GitHub\AppService;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gives a thread sandbox GitHub tokens limited to the repositories of the
 * thread. The git credential helper holds a token with contents write (the
 * sandbox pushes work in progress), the agent's `gh` wrapper a read-only one.
 * Token values travel on stdin only and never appear in a command line.
 */
class ThreadGitHubCredentials
{
    private const GIT_TOKEN_FILE = '/home/yak/.config/yak/git-token';

    private const GH_TOKEN_FILE = '/home/yak/.config/yak/gh-token';

    private const CREDENTIAL_HELPER = '!f() { echo protocol=https; echo host=github.com; echo username=x-access-token; echo "password=$(cat ' . self::GIT_TOKEN_FILE . ')"; }; f';

    public function __construct(private AppService $github, private SandboxManager $sandboxes) {}

    public function install(Thread $thread): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            return;
        }

        $names = $this->repositoryNames($thread);

        $gitToken = $this->github->getScopedInstallationToken($installationId, $names, ['contents' => 'write', 'metadata' => 'read']);
        $ghToken = $this->github->getReadOnlyInstallationToken($installationId, $names);

        $this->writeTokenFile($thread, self::GIT_TOKEN_FILE, $gitToken);
        $this->writeTokenFile($thread, self::GH_TOKEN_FILE, $ghToken);

        $configured = $this->sandboxes->exec($thread, ['git', 'config', '--global', 'credential.https://github.com.helper', self::CREDENTIAL_HELPER]);

        if ($configured->failed()) {
            throw new RuntimeException('Could not configure the git credential helper in the sandbox');
        }
    }

    /** Mints fresh tokens, for a repository added to a running thread. */
    public function refresh(Thread $thread): void
    {
        $this->install($thread);
    }

    /**
     * @return list<string>
     */
    private function repositoryNames(Thread $thread): array
    {
        $slugs = $thread->repositories()->pluck('repo')->all();

        if ($slugs === []) {
            $slugs = [$thread->repo];
        }

        return array_values(array_unique(array_map(fn (string $slug): string => Str::afterLast($slug, '/'), $slugs)));
    }

    private function writeTokenFile(Thread $thread, string $path, string $token): void
    {
        $written = $this->sandboxes->exec($thread, ['sh', '-c', 'mkdir -p "$(dirname "$1")" && umask 077 && cat > "$1"', 'sh', $path], stdin: $token);

        if ($written->failed()) {
            throw new RuntimeException('Could not write a GitHub token into the sandbox');
        }
    }
}
