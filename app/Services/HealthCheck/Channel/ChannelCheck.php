<?php

namespace App\Services\HealthCheck\Channel;

use App\Services\HealthCheck\HealthCheck;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthSection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

abstract class ChannelCheck implements HealthCheck
{
    public function section(): HealthSection
    {
        return HealthSection::Channels;
    }

    /**
     * Map an HTTP client exception to a user-readable detail string.
     */
    protected function detailForHttpException(\Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return 'Unreachable — ' . $this->truncate($e->getMessage(), 120);
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();
            $body = $this->truncate((string) $e->response->body(), 120);

            return match (true) {
                $status === 401 => "401 Unauthorized — credentials rejected ({$body})",
                $status === 403 => "403 Forbidden — token lacks required scope ({$body})",
                $status >= 500 => "HTTP {$status} — service error ({$body})",
                default => "HTTP {$status} — {$body}",
            };
        }

        return 'Check crashed: ' . class_basename($e) . ' — ' . $this->truncate($e->getMessage(), 120);
    }

    protected function truncate(string $value, int $length): string
    {
        $value = trim($value);

        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length) . '…';
    }

    /**
     * Wrap a body of work so any uncaught throwable becomes an Error result
     * rather than a 500. Known HTTP errors get friendlier messages.
     */
    protected function safely(callable $callback): HealthResult
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            report($e);

            return HealthResult::error($this->detailForHttpException($e));
        }
    }
}
