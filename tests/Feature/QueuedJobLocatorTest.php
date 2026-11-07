<?php

use App\Services\QueuedJobLocator;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('finds a job by uuid on the database driver', function () {
    config(['queue.default' => 'database']);

    DB::table('jobs')->insert([
        'queue' => 'yak-claude',
        'payload' => json_encode(['uuid' => 'abc-123', 'displayName' => 'App\\Jobs\\RunYakJob']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);

    $locator = new QueuedJobLocator;

    expect($locator->contains('yak-claude', 'abc-123'))->toBeTrue()
        ->and($locator->contains('yak-claude', 'missing'))->toBeFalse()
        ->and($locator->contains('default', 'abc-123'))->toBeFalse();
});

it('finds pending, delayed and reserved jobs on the redis driver', function (string $listedIn) {
    config(['queue.default' => 'redis']);

    $payload = json_encode(['uuid' => 'abc-123']);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('lrange')->with('queues:yak-claude', 0, -1)->andReturn($listedIn === 'pending' ? [$payload] : []);
    $connection->shouldReceive('zrange')->with('queues:yak-claude:delayed', 0, -1)->andReturn($listedIn === 'delayed' ? [$payload] : []);
    $connection->shouldReceive('zrange')->with('queues:yak-claude:reserved', 0, -1)->andReturn($listedIn === 'reserved' ? [$payload] : []);
    Redis::shouldReceive('connection')->andReturn($connection);

    $locator = new QueuedJobLocator;

    expect($locator->contains('yak-claude', 'abc-123'))->toBeTrue()
        ->and($locator->contains('yak-claude', 'missing'))->toBeFalse();
})->with(['pending', 'delayed', 'reserved']);

it('reports nothing for drivers it cannot search', function () {
    config(['queue.default' => 'sync']);

    expect((new QueuedJobLocator)->contains('yak-claude', 'abc-123'))->toBeFalse();
});
