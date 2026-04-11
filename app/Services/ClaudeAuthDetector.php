<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;

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
        'please run `claude login`',
        'please run \'claude login\'',
        'subscription expired',
        'unauthorized',
        'auth token',
        'login required',
        'not logged in',
        'session expired',
    ];

    public static function isAuthError(ProcessResult $result): bool
    {
        if ($result->successful()) {
            return false;
        }

        $output = strtolower($result->output().' '.$result->errorOutput());

        foreach (self::AUTH_ERROR_PATTERNS as $pattern) {
            if (str_contains($output, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function formatErrorMessage(ProcessResult $result): string
    {
        $errorOutput = trim($result->errorOutput() ?: $result->output());

        return "Claude CLI authentication error: {$errorOutput}. Please re-authenticate with `claude login`.";
    }
}
