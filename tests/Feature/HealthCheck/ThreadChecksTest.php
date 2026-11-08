<?php

use App\Models\ClaudeAccount;
use App\Services\HealthCheck\BrokerCheck;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\SystemAccountCheck;
use Illuminate\Support\Facades\Redis;

function fakeRedis(bool $reachable, bool $brokerAlive): void
{
    $connection = Mockery::mock();

    if ($reachable) {
        $connection->shouldReceive('ping')->andReturn(true);
        $connection->shouldReceive('exists')->with('broker:alive')->andReturn($brokerAlive ? 1 : 0);
    } else {
        $connection->shouldReceive('ping')->andThrow(new RuntimeException('connection refused'));
    }

    Redis::shouldReceive('connection')->with('threads')->andReturn($connection);
}

it('the broker check is Ok when Redis answers and the broker heartbeat is present', function () {
    fakeRedis(reachable: true, brokerAlive: true);

    expect((new BrokerCheck)->run()->status)->toBe(HealthStatus::Ok);
});

it('the broker check fails when Redis is not reachable', function () {
    fakeRedis(reachable: false, brokerAlive: false);

    $result = (new BrokerCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('Redis is not reachable');
});

it('the broker check fails when the broker heartbeat is missing', function () {
    fakeRedis(reachable: true, brokerAlive: false);

    $result = (new BrokerCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('broker is not running');
});

it('the system account check fails with no system account', function () {
    ClaudeAccount::factory()->create();

    $result = (new SystemAccountCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('No system account')
        ->and($result->action?->url)->toBe(route('settings.claude-accounts'));
});

it('the system account check fails when every system account is invalid or expired', function () {
    ClaudeAccount::factory()->system()->invalid()->create();
    ClaudeAccount::factory()->system()->expired()->create();

    expect((new SystemAccountCheck)->run()->status)->toBe(HealthStatus::Error);
});

it('the system account check is Ok with one usable system account', function () {
    ClaudeAccount::factory()->system()->invalid()->create();
    ClaudeAccount::factory()->system()->create(['name' => 'Yak Max']);

    $result = (new SystemAccountCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toContain('Yak Max');
});
