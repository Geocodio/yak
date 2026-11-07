<?php

use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Models\Repository;
use App\Services\PreviewRepair\PreviewFailureFingerprint;

const DOCKERFILE_FAILURE = 'Command failed in container %s (phase=refresh): #0 building with "default" instance using docker driver

#1 [internal] load build definition from Dockerfile
#1 transferring dockerfile: 2B done
#1 DONE %s
ERROR: failed to build: failed to solve: failed to read dockerfile: open Dockerfile: no such file or directory
';

it('normalizes container names shas timings and step ids', function () {
    $first = sprintf(DOCKERFILE_FAILURE, 'deploy-01m5m6fara66k0na6sjrfzy6ye', '0.1s');
    $second = sprintf(DOCKERFILE_FAILURE, 'deploy-01m50qzn7qpydww8cjdrh09kaz', '0.0s') . "\nat 4cd838f1e9e414d09ac1452aefabcc86d5cce58c 2026-10-26T13:46:31Z";
    $first .= "\nat 38bca2bb2b10d3d9180f50bddf30cc5ff2b19417 2026-10-26T13:42:25Z";

    expect(PreviewFailureFingerprint::normalize($first))->toBe(PreviewFailureFingerprint::normalize($second));
});

it('treats the same cause across deploy and rebuild prefixes as one', function () {
    $repository = Repository::factory()->create();
    $deploy = BranchDeployment::factory()->for($repository)->failed('deploy: Failed to start container: boom')->create();
    $rebuild = BranchDeployment::factory()->for($repository)->failed('rebuild: Failed to start container: boom')->create();

    expect(PreviewFailureFingerprint::for($deploy)->hash)->toBe(PreviewFailureFingerprint::for($rebuild)->hash)
        ->and(PreviewFailureFingerprint::for($deploy)->phase)->toBe('lifecycle');
});

it('uses the last error log phase and output', function () {
    $deployment = BranchDeployment::factory()->failed('rebuild: whatever')->create();
    DeploymentLog::record($deployment, 'error', 'cold_start', '$ docker compose up -d');
    $log = DeploymentLog::record($deployment, 'error', 'refresh', '$ npm run build');
    $log->chunks()->create(['chunk' => "line one\nnpm ERR! missing script: build\n", 'created_at' => now()]);
    DeploymentLog::record($deployment, 'error', 'lifecycle', 'Rebuild failed: whatever');

    $fingerprint = PreviewFailureFingerprint::for($deployment);

    expect($fingerprint->phase)->toBe('refresh')
        ->and($fingerprint->excerpt)->toEndWith('npm ERR! missing script: build');
});

it('keeps only the last twenty lines of output', function () {
    $deployment = BranchDeployment::factory()->failed('rebuild: whatever')->create();
    $log = DeploymentLog::record($deployment, 'error', 'refresh', '$ make');
    $log->chunks()->create(['chunk' => implode("\n", array_map(fn (int $line): string => "line {$line}", range(1, 30))), 'created_at' => now()]);

    $excerpt = PreviewFailureFingerprint::for($deployment)->excerpt;

    expect($excerpt)->toStartWith('line 11')->and($excerpt)->toEndWith('line 30');
});

it('never shares a fingerprint between repositories', function () {
    $first = BranchDeployment::factory()->failed('deploy: boom')->create();
    $second = BranchDeployment::factory()->failed('deploy: boom')->create();

    expect(PreviewFailureFingerprint::for($first)->hash)->not->toBe(PreviewFailureFingerprint::for($second)->hash);
});

it('uses the exception message when given', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $fingerprint = PreviewFailureFingerprint::for($deployment, 'Command failed in container x (phase=refresh): exit 1');

    expect($fingerprint->excerpt)->toContain('exit 1');
});

it('reads the health probe phase from a timeout message', function () {
    $deployment = BranchDeployment::factory()->failed('deploy: Deployment start timed out during phase: health_probe')->create();

    expect(PreviewFailureFingerprint::for($deployment)->phase)->toBe('health_probe');
});

it('ignores a command failure from an earlier run', function () {
    $deployment = BranchDeployment::factory()->failed('deploy: Deployment start timed out during phase: health_probe')->create();
    DeploymentLog::record($deployment, 'error', 'refresh', '$ npm run build');
    DeploymentLog::record($deployment, 'info', 'lifecycle', 'Starting container');
    DeploymentLog::record($deployment, 'info', 'refresh', '$ npm run build');
    DeploymentLog::record($deployment, 'error', 'lifecycle', 'Deploy failed: Deployment start timed out during phase: health_probe');

    expect(PreviewFailureFingerprint::for($deployment)->phase)->toBe('health_probe');
});
