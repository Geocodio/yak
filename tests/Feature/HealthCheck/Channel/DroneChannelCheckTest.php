<?php

use App\Services\HealthCheck\Channel\DroneChannelCheck;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'yak.channels.drone.url' => 'https://drone.example.com',
        'yak.channels.drone.token' => 'drone-token',
    ]);
});

it('returns Ok when /api/user returns 200', function () {
    Http::fake([
        'drone.example.com/api/user' => Http::response(['login' => 'yak-bot', 'email' => 'yak@example.com']),
    ]);

    $result = (new DroneChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('yak-bot');
});

it('returns Error on 401', function () {
    Http::fake([
        'drone.example.com/api/user' => Http::response('unauthorized', 401),
    ]);

    $result = (new DroneChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('401');
});
