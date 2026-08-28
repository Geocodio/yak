<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Classifies a failed CLI run as an auth error from ProcessResult output.
 *
 * SandboxedAgentRunner::run() applies isAuthError() to the ENTIRE
 * stream-JSON of a failed agent run -- session UUIDs, `duration_ms`,
 * `total_cost_usd`, tool output, and the agent's own prose -- so every
 * pattern here must be specific enough not to fire on that noise. Bare
 * substrings like `oauth` or `401` are too loose: they match a UUID
 * fragment, a `duration_ms` value, an HTTP 401 the agent legitimately hit
 * in the repo it's working on, or the `.oauth_refresh.lock` path. Keep
 * patterns anchored to real auth-error phrasing (`http 401`, `oauth
 * token`, ...); callers with a narrower, more controlled haystack (e.g.
 * ClaudeAuthCheck's own probe output) that need looser matching should do
 * that classification locally rather than loosening this shared list.
 */
class ClaudeAuthDetector
{
    private const AUTH_ERROR_PATTERNS = [
        'not authenticated',
        'authentication required',
        'authentication_error',
        'token expired',
        'token has expired',
        'invalid_api_key',
        'invalid api key',
        'invalid_grant',
        'please run `claude login`',
        'please run \'claude login\'',
        'subscription expired',
        'unauthorized',
        'auth token',
        'login required',
        'not logged in',
        'session expired',
        'http 401',
        'status 401',
        'oauth token',
        'oauth error',
    ];

    public static function isAuthError(ProcessResult $result): bool
    {
        if ($result->successful()) {
            return false;
        }

        $output = strtolower($result->output() . ' ' . $result->errorOutput());

        foreach (self::AUTH_ERROR_PATTERNS as $pattern) {
            if (str_contains($output, $pattern)) {
                return true;
            }
        }

        // "please run ... login" without the exact `claude login` phrasing
        // above (CLI wording changes across versions).
        if (str_contains($output, 'please run') && str_contains($output, 'login')) {
            return true;
        }

        return false;
    }

    public static function formatErrorMessage(ProcessResult $result): string
    {
        $errorOutput = trim($result->errorOutput() ?: $result->output());

        return "Claude CLI authentication error: {$errorOutput}. Please re-authenticate with `claude login`.";
    }
}
