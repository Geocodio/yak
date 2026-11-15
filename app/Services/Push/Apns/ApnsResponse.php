<?php

namespace App\Services\Push\Apns;

/**
 * What Apple answered, reduced to the three facts a caller needs. Status 0 means no answer arrived (a timeout or a connection failure).
 */
final readonly class ApnsResponse
{
    public function __construct(public int $status, public ?string $reason = null, public ?string $channelId = null) {}

    /**
     * An answer that never arrived. Only the exception's short class name is kept: its message carries the request URL, which holds the device token.
     */
    public static function timeout(string $failureClass): self
    {
        return new self(0, class_basename($failureClass));
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isTokenGone(): bool
    {
        return ($this->status === 400 && in_array($this->reason, ['BadDeviceToken', 'DeviceTokenNotForTopic'], true))
            || ($this->status === 410 && $this->reason === 'Unregistered');
    }

    public function isProviderTokenProblem(): bool
    {
        return $this->status === 403 && in_array($this->reason, ['ExpiredProviderToken', 'InvalidProviderToken'], true);
    }

    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
