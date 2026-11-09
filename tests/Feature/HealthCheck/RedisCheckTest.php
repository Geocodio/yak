<?php

use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\RedisCheck;
use App\Services\HealthCheck\Registry;
use Illuminate\Support\Facades\Redis;

it('returns Ok when redis answers', function () {
    Redis::shouldReceive('connection->ping')->andReturn(true);

    expect((new RedisCheck)->run()->status)->toBe(HealthStatus::Ok);
});

it('returns Error when redis cannot be reached', function () {
    Redis::shouldReceive('connection->ping')->andThrow(new RedisException('Connection refused'));

    $result = (new RedisCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('Connection refused');
});

it('is registered as a system check', function () {
    expect(app(Registry::class)->get('redis'))->toBeInstanceOf(RedisCheck::class)
        ->and((new RedisCheck)->section())->toBe(HealthSection::System);
});
