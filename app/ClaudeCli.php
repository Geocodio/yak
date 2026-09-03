<?php

namespace App;

use App\Channels\GitHub\AppService;
use App\Exceptions\ClaudeCliException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Throwable;

/**
 * Thin wrapper around the host-side `claude` CLI used by the /skills dashboard
 * page to manage plugins and marketplaces under /home/yak/.claude. This is
 * orthogonal to the agent runner — the agent itself runs inside Incus
 * sandboxes via SandboxedAgentRunner and never goes through this class.
 */
class ClaudeCli
{
    public function exec(string $args, int $timeout = 60): ProcessResult
    {
        $command = $this->interactiveCommand($args);

        try {
            return Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException $e) {
            throw new ClaudeCliException("claude {$args} timed out after {$timeout}s", previous: $e);
        }
    }

    /**
     * Builds the same shell command string `exec()` runs, without running
     * it. Used by callers that need to drive the process themselves (e.g.
     * McpLoginJob, which needs a PTY and an interactive stdin) instead of
     * going through Process::run().
     */
    public function interactiveCommand(string $args): string
    {
        return sprintf(
            'env HOME=/home/yak CLAUDE_CONFIG_DIR=/home/yak/.claude %sclaude %s',
            $this->gitCredentialEnv(),
            $args,
        );
    }

    /**
     * Build the `env` assignments that let the CLI clone and refresh private
     * GitHub marketplaces.
     *
     * The token rides along as a throwaway git credential helper via
     * GIT_CONFIG_* rather than being baked into a remote URL, so it never
     * lands in known_marketplaces.json and an expired token simply stops
     * working instead of poisoning the checkout. Plugins installed this way
     * land in the shared /home/yak/.claude, which every sandbox bind-mounts.
     *
     * Returns an empty string when no token is available, leaving public
     * marketplaces (and local dev) behaving exactly as before.
     */
    private function gitCredentialEnv(): string
    {
        $token = $this->githubToken();

        if ($token === null) {
            return '';
        }

        $helper = "!f() { echo \"protocol=https\nhost=github.com\nusername=x-access-token\npassword={$token}\"; }; f";

        return sprintf(
            'GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=%s GIT_CONFIG_VALUE_0=%s ',
            escapeshellarg('credential.https://github.com.helper'),
            escapeshellarg($helper),
        );
    }

    /**
     * Prefer an explicitly configured token — it is the only way to reach a
     * marketplace outside the GitHub App's installation — and otherwise mint
     * an installation token, which covers every repo the app is installed on.
     */
    private function githubToken(): ?string
    {
        $override = config('yak.skills_github_token');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            return null;
        }

        try {
            return app(AppService::class)->getInstallationToken($installationId);
        } catch (Throwable $e) {
            Log::warning('Could not mint a GitHub token for the claude CLI; private marketplaces will fail.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
