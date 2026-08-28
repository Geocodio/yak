<?php

namespace App\Services\HealthCheck;

use App\Services\ClaudeAuthDetector;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Verifies the shared Claude Max session is usable by running a real
 * inference call, not `claude auth status` (which does not perform a
 * token refresh and only answers "is the host logged in").
 *
 * The shared config dir at `yak.sandbox.claude_config_source` is mounted
 * into every sandbox at create time. We probe it from the yak app
 * container (where the `claude` binary is also installed for the
 * /skills dashboard); if the probe succeeds here, every sandbox that
 * gets the same mount will succeed too. On two consecutive failures, sets
 * self::UNUSABLE_CACHE_KEY so the HoldsForClaudeAuth job middleware can
 * hold tasks until the session is fixed.
 *
 * This check performs the real (up to 120s) inference call and must only
 * ever run from the scheduled `yak:healthcheck` command, never from a web
 * request — see HealthRow, which renders the cached result this check
 * publishes to self::LAST_RESULT_CACHE_KEY instead of invoking run().
 */
class ClaudeAuthCheck implements HealthCheck
{
    /**
     * A .oauth_refresh.lock older than this is an orphan (a real refresh
     * completes in seconds). Left in place it blocks every subsequent
     * refresh — on the host and in every sandbox that gets the shared
     * mount — until someone deletes it by hand. Lowered from 600: with the
     * mount shared, a stuck lock now blocks every sandbox at once rather
     * than one, so the sweep needs to fire sooner.
     */
    private const STALE_LOCK_SECONDS = 300;

    /**
     * Cache flag read by the HoldsForClaudeAuth job middleware.
     */
    public const UNUSABLE_CACHE_KEY = 'yak:claude-auth-unusable';

    /**
     * How long the queue gate (and the consecutive-failure counter) stay
     * set once tripped. If nobody re-authenticates within this window,
     * held jobs eventually hit their own retryUntil() and fail terminally
     * — the outcome the gate exists to avoid — so this should stay well
     * above a realistic time-to-notice-and-fix.
     */
    private const GATE_TTL_HOURS = 24;

    /**
     * A single failure could be a transient API 529, a rate/usage limit,
     * a network blip, or a missing `claude` binary — none of which mean
     * the session is unauthenticated. Gating the queue on one bad probe
     * would hold every yak-claude job over something the very next
     * 15-minute run would likely clear on its own. Require this many
     * consecutive failures before setting the gate flag.
     */
    private const CONSECUTIVE_FAILURES_TO_GATE = 2;

    private const CONSECUTIVE_FAILURE_CACHE_KEY = 'yak:claude-auth-consecutive-failures';

    /**
     * Publishes the most recent probe result (and when it ran) so
     * HealthRow can render it without re-running the probe itself.
     */
    public const LAST_RESULT_CACHE_KEY = 'yak:claude-auth-last-result';

    public function id(): string
    {
        return 'claude-auth';
    }

    public function name(): string
    {
        return 'Claude Max Session';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $configDir = (string) config('yak.sandbox.claude_config_source', '/home/yak/.claude');

        $this->sweepStaleOauthRefreshLock($configDir);

        // A real inference call, not `claude auth status`: status does not
        // exercise a refresh and answers "is the host logged in", not "can a
        // task start". ANTHROPIC_API_KEY is unset because with it set the CLI
        // takes the billed API path and reports healthy while the
        // subscription session is dead.
        $command = sprintf(
            'env -u ANTHROPIC_API_KEY HOME=%s CLAUDE_CONFIG_DIR=%s claude --model claude-haiku-4-5 -p %s',
            escapeshellarg(dirname($configDir)),
            escapeshellarg($configDir),
            escapeshellarg('Reply with exactly: ok'),
        );

        try {
            $result = Process::timeout(120)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return $this->recordResult(
                $this->unusable('Timed out probing the Claude session', isAuthFailure: false),
            );
        }

        if ($result->successful()) {
            Cache::forget(self::UNUSABLE_CACHE_KEY);
            Cache::forget(self::CONSECUTIVE_FAILURE_CACHE_KEY);

            return $this->recordResult(HealthResult::ok('Authenticated'));
        }

        return $this->recordResult($this->unusable(
            $this->failureReason($result),
            isAuthFailure: $this->isAuthFailure($result),
        ));
    }

    /**
     * Classifies this probe's own failure as an auth error.
     *
     * Unlike SandboxedAgentRunner, which runs ClaudeAuthDetector::isAuthError()
     * over an entire agent's multi-KB stream-JSON output, this probe's output
     * is a single short CLI invocation with no session UUIDs, cost/duration
     * fields, or agent prose to false-positive on. It is safe to also match
     * the bare `oauth`/`401` substrings that were deliberately kept out of
     * the shared detector for that noisier caller.
     */
    private function isAuthFailure(ProcessResult $result): bool
    {
        if (ClaudeAuthDetector::isAuthError($result)) {
            return true;
        }

        $output = strtolower($result->output() . ' ' . $result->errorOutput());

        return str_contains($output, 'oauth') || str_contains($output, '401');
    }

    /**
     * Builds the unhealthy result and (only after enough consecutive
     * failures) trips the queue gate. Message classification is
     * independent of gating: an operator should never be told to
     * re-authenticate over a transient failure, even before the gate
     * itself trips.
     */
    private function unusable(string $reason, bool $isAuthFailure): HealthResult
    {
        $consecutiveFailures = (int) Cache::get(self::CONSECUTIVE_FAILURE_CACHE_KEY, 0) + 1;

        Cache::put(self::CONSECUTIVE_FAILURE_CACHE_KEY, $consecutiveFailures, now()->addHours(self::GATE_TTL_HOURS));

        $gated = $consecutiveFailures >= self::CONSECUTIVE_FAILURES_TO_GATE;

        if ($gated) {
            Cache::put(self::UNUSABLE_CACHE_KEY, true, now()->addHours(self::GATE_TTL_HOURS));
        }

        if ($isAuthFailure) {
            $detail = $reason . ' — re-authenticate: ssh the host and run `yak-claude-login`, then type /login';
        } else {
            $detail = $reason . ' — transient or unrelated to authentication; not a re-authentication issue.';

            if (! $gated) {
                $detail .= ' Not yet gating the queue (will hold jobs after one more consecutive failure).';
            }
        }

        return HealthResult::error($detail);
    }

    /**
     * Distinguishes a clearly-unauthenticated CLI (worth telling an
     * operator to re-authenticate) from everything else — a missing
     * binary, a rate limit, an overloaded upstream, a network blip —
     * where "re-authenticate" is actively wrong guidance.
     */
    private function failureReason(ProcessResult $result): string
    {
        if ($result->exitCode() === 127) {
            return 'claude binary not found in the probe environment (exit 127)';
        }

        $output = trim($result->errorOutput() ?: $result->output());

        if ($output === '') {
            return "Claude session probe failed (exit {$result->exitCode()})";
        }

        return 'Claude session probe failed: ' . Str::limit($output, 200);
    }

    private function recordResult(HealthResult $result): HealthResult
    {
        Cache::put(self::LAST_RESULT_CACHE_KEY, [
            'result' => $result,
            'checked_at' => now(),
        ], now()->addDay());

        return $result;
    }

    private function sweepStaleOauthRefreshLock(string $configDir): void
    {
        $lockDir = $configDir . '/.oauth_refresh.lock';

        if (! is_dir($lockDir)) {
            return;
        }

        $age = time() - (int) filemtime($lockDir);

        // A fresh lock likely belongs to an in-flight refresh; deleting it
        // could corrupt a rotation about to be persisted.
        if ($age < self::STALE_LOCK_SECONDS) {
            return;
        }

        File::deleteDirectory($lockDir);

        Log::channel('yak')->warning('Swept stale .oauth_refresh.lock', [
            'lock_dir' => $lockDir,
            'age_seconds' => $age,
        ]);
    }
}
