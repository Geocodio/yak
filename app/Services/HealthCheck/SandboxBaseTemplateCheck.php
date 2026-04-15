<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Verifies the base sandbox template + ready snapshot exists.
 *
 * Without `yak-base/ready`, setup tasks have nothing to clone from.
 * The template is built by the `incus` Ansible role; it should normally
 * exist after provisioning.
 */
class SandboxBaseTemplateCheck implements HealthCheck
{
    public function id(): string
    {
        return 'sandbox-base-template';
    }

    public function name(): string
    {
        return 'Sandbox Base Template';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $template = (string) config('yak.sandbox.base_template', 'yak-base');
        $snapshot = (string) config('yak.sandbox.snapshot_name', 'ready');

        try {
            $result = Process::timeout(5)->run("incus snapshot list {$template} --format csv 2>/dev/null");
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out');
        }

        if (! $result->successful()) {
            return HealthResult::error("Base template '{$template}' not found — run the `incus` Ansible role to build it");
        }

        $snapshots = array_map(fn (string $line) => trim(explode(',', $line)[0] ?? ''), explode("\n", trim($result->output())));

        if (! in_array($snapshot, $snapshots, true)) {
            return HealthResult::error("Base template '{$template}' has no '{$snapshot}' snapshot — re-run the `incus` Ansible role");
        }

        return HealthResult::ok("'{$template}/{$snapshot}' ready");
    }
}
