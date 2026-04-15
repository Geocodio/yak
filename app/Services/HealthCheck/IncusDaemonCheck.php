<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Verifies the queue worker can talk to the host's Incus daemon over the
 * mounted Unix socket. If this check fails, no sandboxes can be created
 * and the agent grinds to a halt.
 */
class IncusDaemonCheck implements HealthCheck
{
    public function id(): string
    {
        return 'incus-daemon';
    }

    public function name(): string
    {
        return 'Incus Daemon';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        try {
            $result = Process::timeout(5)->run('incus list --format csv -c n');
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out — Incus socket may not be mounted');
        }

        if ($result->successful()) {
            $count = count(array_filter(explode("\n", trim($result->output()))));

            return HealthResult::ok("Reachable — {$count} containers");
        }

        return HealthResult::error('Cannot reach Incus daemon — check that /var/lib/incus/unix.socket is mounted and the worker is in the incus-admin group');
    }
}
