<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('moves every job left on the database queue onto redis', function () {
    Queue::fake();

    DB::table('jobs')->insert([
        ['queue' => 'yak-claude', 'payload' => json_encode(['uuid' => 'pending-1', 'attempts' => 0]), 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->addMinutes(2)->getTimestamp(), 'created_at' => now()->getTimestamp()],
        ['queue' => 'default', 'payload' => json_encode(['uuid' => 'reserved-1', 'attempts' => 0]), 'attempts' => 1, 'reserved_at' => now()->getTimestamp(), 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()],
    ]);

    $this->artisan('yak:queue:move-database-jobs')->assertSuccessful();

    $pushes = collect(Queue::rawPushes());

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($pushes)->toHaveCount(2)
        ->and($pushes->firstWhere('queue', 'yak-claude')['payload'])->toContain('"uuid":"pending-1"')
        ->and(json_decode($pushes->firstWhere('queue', 'default')['payload'], true)['attempts'])->toBe(1);
});

it('does nothing when the database queue is empty', function () {
    Queue::fake();

    $this->artisan('yak:queue:move-database-jobs')->assertSuccessful();

    expect(Queue::rawPushes())->toBe([]);
});
