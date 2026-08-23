<?php

use App\Jobs\RunYakJob;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// --- Replay protection: stale webhookTimestamp ---

test('rejects a created event with a stale webhookTimestamp', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Bus::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);

    $payload = (string) json_encode(array_merge(
        json_decode(agentSessionCreatedPayload(), true),
        ['webhookTimestamp' => now()->subMinutes(5)->getTimestampMs()],
    ));

    $response = test()->call('POST', '/webhooks/linear', content: $payload, server: [
        'HTTP_Linear-Signature' => signLinearPayload($payload, $secret),
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();
    expect(YakTask::count())->toBe(0);
    Bus::assertNotDispatched(RunYakJob::class);
});

// --- Delivery idempotency: duplicate Linear-Delivery header ---

test('processes a delivery id only once', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Bus::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);

    $payload = agentSessionCreatedPayload();
    $signature = signLinearPayload($payload, $secret);

    $post = fn () => test()->call('POST', '/webhooks/linear', content: $payload, server: [
        'HTTP_Linear-Signature' => $signature,
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'HTTP_Linear-Delivery' => 'delivery-uuid-1',
        'CONTENT_TYPE' => 'application/json',
    ]);

    $post()->assertSuccessful();
    expect(YakTask::count())->toBe(1);

    $post()->assertSuccessful();
    expect(YakTask::count())->toBe(1);
    Bus::assertDispatchedTimes(RunYakJob::class, 1);
});

// --- Back-compat: no timestamp, no delivery header still works ---

test('still processes events with no timestamp and no delivery header', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Bus::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);

    $payload = agentSessionCreatedPayload();

    $response = test()->call('POST', '/webhooks/linear', content: $payload, server: [
        'HTTP_Linear-Signature' => signLinearPayload($payload, $secret),
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();
    expect(YakTask::count())->toBe(1);
});
