<?php

namespace App\Http\Concerns;

use App\Facades\Telemetry;
use Closure;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * One `webhook.received` event per inbound delivery, classified from the
 * JSON the handler already returns: `skipped` and `filtered` reasons,
 * `handled` outcomes, 409 duplicates, and thrown exceptions. This is how
 * the Analytics page can show "people asked, Yak declined" alongside the
 * tasks that were created.
 */
trait RecordsWebhookTelemetry
{
    /**
     * @param  Closure(): JsonResponse  $handler
     * @param  array<string, mixed>  $properties
     */
    protected function recordWebhook(string $channel, string $event, Closure $handler, array $properties = []): JsonResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = $handler();
        } catch (Throwable $e) {
            Telemetry::record('webhook.received', [
                'channel' => $channel,
                'event' => $event,
                'outcome' => 'error',
                'reason' => class_basename($e),
            ] + $properties, source: $channel, durationMs: Telemetry::elapsedMs($startedAt));

            throw $e;
        }

        [$outcome, $reason] = $this->classifyWebhookResponse($response);

        Telemetry::record('webhook.received', [
            'channel' => $channel,
            'event' => $event,
            'outcome' => $outcome,
            'reason' => $reason,
            'status' => $response->getStatusCode(),
        ] + $properties, source: $channel, durationMs: Telemetry::elapsedMs($startedAt));

        return $response;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function classifyWebhookResponse(JsonResponse $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $response->getData(true);

        if ($response->getStatusCode() === 409) {
            return ['duplicate', 'duplicate'];
        }

        if (isset($payload['filtered'])) {
            return ['rejected', (string) $payload['filtered']];
        }

        if (isset($payload['skipped'])) {
            return ['skipped', (string) $payload['skipped']];
        }

        if (isset($payload['handled'])) {
            return ['accepted', (string) $payload['handled']];
        }

        if (isset($payload['task_id']) || ($payload['dispatched'] ?? false) === true || ($payload['updated'] ?? false) === true) {
            return ['accepted', null];
        }

        if ($response->getStatusCode() >= 400) {
            return ['error', (string) ($payload['error'] ?? $response->getStatusCode())];
        }

        return ['ignored', null];
    }
}
