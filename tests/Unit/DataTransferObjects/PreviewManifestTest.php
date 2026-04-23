<?php

use App\DataTransferObjects\PreviewManifest;
use Tests\TestCase;

uses(TestCase::class);

it('constructs from a fully-populated array', function () {
    $manifest = PreviewManifest::fromArray([
        'port' => 8080,
        'health_probe_path' => '/up',
        'cold_start' => 'docker compose up -d',
        'checkout_refresh' => 'docker compose restart web',
        'wake_timeout_seconds' => 90,
        'cold_start_timeout_seconds' => 45,
        'checkout_refresh_timeout_seconds' => 30,
        'health_probe_timeout_seconds' => 15,
    ]);

    expect($manifest->port)->toBe(8080);
    expect($manifest->healthProbePath)->toBe('/up');
    expect($manifest->coldStart)->toBe('docker compose up -d');
    expect($manifest->checkoutRefresh)->toBe('docker compose restart web');
    expect($manifest->wakeTimeoutSeconds)->toBe(90);
    expect($manifest->coldStartTimeoutSeconds)->toBe(45);
    expect($manifest->checkoutRefreshTimeoutSeconds)->toBe(30);
    expect($manifest->healthProbeTimeoutSeconds)->toBe(15);
});

it('falls back to config defaults when fields are missing', function () {
    config()->set('yak.deployments.default_port', 80);
    config()->set('yak.deployments.default_health_probe_path', '/');
    config()->set('yak.deployments.default_wake_timeout_seconds', 120);
    config()->set('yak.deployments.default_cold_start_timeout_seconds', 60);
    config()->set('yak.deployments.default_checkout_refresh_timeout_seconds', 60);
    config()->set('yak.deployments.default_health_probe_timeout_seconds', 30);

    $manifest = PreviewManifest::fromArray([
        'cold_start' => 'docker compose up -d',
    ]);

    expect($manifest->port)->toBe(80);
    expect($manifest->healthProbePath)->toBe('/');
    expect($manifest->checkoutRefresh)->toBe('');
    expect($manifest->wakeTimeoutSeconds)->toBe(120);
    expect($manifest->coldStartTimeoutSeconds)->toBe(60);
    expect($manifest->checkoutRefreshTimeoutSeconds)->toBe(60);
    expect($manifest->healthProbeTimeoutSeconds)->toBe(30);
});

it('treats null and empty manifests equivalently', function () {
    config()->set('yak.deployments.default_port', 80);

    expect(PreviewManifest::fromArray(null)->port)->toBe(80);
    expect(PreviewManifest::fromArray([])->port)->toBe(80);
});

it('round-trips through toArray', function () {
    $source = [
        'port' => 3000,
        'health_probe_path' => '/healthz',
        'cold_start' => 'pnpm dev',
        'checkout_refresh' => 'pnpm install && pnpm build',
        'wake_timeout_seconds' => 200,
        'cold_start_timeout_seconds' => 100,
        'checkout_refresh_timeout_seconds' => 70,
        'health_probe_timeout_seconds' => 30,
    ];

    expect(PreviewManifest::fromArray($source)->toArray())->toBe($source);
});
