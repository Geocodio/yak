<?php

use Illuminate\Console\Scheduling\Schedule;

it('flushes telemetry every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'telemetry:flush'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(5);
});

it('prunes thread events daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'yak:prune-thread-events'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');
});
