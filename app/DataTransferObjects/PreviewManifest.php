<?php

namespace App\DataTransferObjects;

final readonly class PreviewManifest
{
    public function __construct(
        public int $port,
        public string $healthProbePath,
        public string $coldStart,
        public string $checkoutRefresh,
        public int $wakeTimeoutSeconds,
        public int $coldStartTimeoutSeconds,
        public int $checkoutRefreshTimeoutSeconds,
        public int $healthProbeTimeoutSeconds,
    ) {}

    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            port: (int) ($data['port'] ?? config('yak.deployments.default_port')),
            healthProbePath: (string) ($data['health_probe_path'] ?? config('yak.deployments.default_health_probe_path')),
            coldStart: (string) ($data['cold_start'] ?? ''),
            checkoutRefresh: (string) ($data['checkout_refresh'] ?? ''),
            wakeTimeoutSeconds: (int) ($data['wake_timeout_seconds'] ?? config('yak.deployments.default_wake_timeout_seconds')),
            coldStartTimeoutSeconds: (int) ($data['cold_start_timeout_seconds'] ?? config('yak.deployments.default_cold_start_timeout_seconds')),
            checkoutRefreshTimeoutSeconds: (int) ($data['checkout_refresh_timeout_seconds'] ?? config('yak.deployments.default_checkout_refresh_timeout_seconds')),
            healthProbeTimeoutSeconds: (int) ($data['health_probe_timeout_seconds'] ?? config('yak.deployments.default_health_probe_timeout_seconds')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'port' => $this->port,
            'health_probe_path' => $this->healthProbePath,
            'cold_start' => $this->coldStart,
            'checkout_refresh' => $this->checkoutRefresh,
            'wake_timeout_seconds' => $this->wakeTimeoutSeconds,
            'cold_start_timeout_seconds' => $this->coldStartTimeoutSeconds,
            'checkout_refresh_timeout_seconds' => $this->checkoutRefreshTimeoutSeconds,
            'health_probe_timeout_seconds' => $this->healthProbeTimeoutSeconds,
        ];
    }
}
