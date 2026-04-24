<?php

use App\Models\BranchDeployment;
use App\Models\DeploymentLog;

it('records a log entry with phase and metadata', function () {
    $deployment = BranchDeployment::factory()->create();

    DeploymentLog::record(
        deployment: $deployment,
        level: 'info',
        phase: 'refresh',
        message: "docker compose build\n... succeeded",
        metadata: ['exit_code' => 0, 'duration_ms' => 1234],
    );

    $log = DeploymentLog::where('branch_deployment_id', $deployment->id)->firstOrFail();

    expect($log->level)->toBe('info');
    expect($log->phase)->toBe('refresh');
    expect($log->message)->toContain('docker compose build');
    expect($log->metadata)->toMatchArray(['exit_code' => 0, 'duration_ms' => 1234]);
});

it('defaults metadata to null when omitted', function () {
    $deployment = BranchDeployment::factory()->create();

    DeploymentLog::record($deployment, 'error', 'cold_start', 'boom');

    $log = DeploymentLog::where('branch_deployment_id', $deployment->id)->firstOrFail();
    expect($log->metadata)->toBeNull();
});
