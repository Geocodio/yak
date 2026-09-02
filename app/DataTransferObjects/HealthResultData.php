<?php

namespace App\DataTransferObjects;

use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The Inertia-facing shape of a single health check result.
 *
 * @phpstan-type HealthResultShape array{status: 'ok'|'warn'|'fail'|'idle', message: string, checkedAgo: ?string, actionLabel: ?string, actionUrl: ?string}
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class HealthResultData implements Arrayable
{
    /**
     * @param  'ok'|'warn'|'fail'|'idle'  $status
     */
    public function __construct(
        public string $status,
        public string $message,
        public ?string $checkedAgo,
        public ?string $actionLabel,
        public ?string $actionUrl,
    ) {}

    public static function from(HealthResult $result, ?string $checkedAgo = null): self
    {
        return new self(
            status: self::mapStatus($result->status),
            message: $result->detail,
            checkedAgo: $checkedAgo,
            actionLabel: $result->action?->label,
            actionUrl: $result->action?->url,
        );
    }

    /**
     * @return HealthResultShape
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'checkedAgo' => $this->checkedAgo,
            'actionLabel' => $this->actionLabel,
            'actionUrl' => $this->actionUrl,
        ];
    }

    /**
     * @return 'ok'|'warn'|'fail'|'idle'
     */
    private static function mapStatus(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Ok => 'ok',
            HealthStatus::Warn => 'warn',
            HealthStatus::Error => 'fail',
            HealthStatus::NotConnected => 'idle',
        };
    }
}
