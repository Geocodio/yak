<?php

use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\RefreshTriggers;
use Illuminate\Support\Facades\Process;

test('clampDestroyAfterHours accepts positive integers up to the server maximum', function (mixed $hours, ?int $expected) {
    expect(RefreshTriggers::clampDestroyAfterHours($hours))->toBe($expected);
})->with([
    'valid' => [168, 168],
    'oversized' => [1000000000, 168],
    'zero' => [0, null],
    'negative' => [-5, null],
    'numeric string' => ['168', null],
    'float' => [1.5, null],
    'null' => [null, null],
]);

test('the configured maximum applies', function () {
    config()->set('yak.threads.max_destroy_after_hours', 24);

    expect(RefreshTriggers::clampDestroyAfterHours(100))->toBe(24);
});

test('the unsigned int column is the ceiling', function () {
    config()->set('yak.threads.max_destroy_after_hours', 9999999999);

    expect(RefreshTriggers::clampDestroyAfterHours(9999999999))->toBe(4294967295);
});

test('an agent edit of .yak/config.yml in the sandbox has no effect', function () {
    $thread = Thread::factory()->create(['repo' => 'acme/widgets']);
    Repository::factory()->create(['slug' => 'acme/widgets']);
    fakeYakFiles(['config.yml' => "version: 1\ndestroy_after_hours: 48\n"]);
    app(SandboxManager::class)->queueExecResult(Process::result('destroy_after_hours: 100'));

    expect(app(RefreshTriggers::class)->destroyAfterHours($thread))->toBe(48)
        ->and(app(SandboxManager::class)->calls)->toBe([]);
});

test('1000000000 is clamped to yak.threads.max_destroy_after_hours', function () {
    $thread = Thread::factory()->create(['repo' => 'acme/widgets']);
    Repository::factory()->create(['slug' => 'acme/widgets']);
    fakeYakFiles(['config.yml' => "version: 1\ndestroy_after_hours: 1000000000\n"]);

    expect(app(RefreshTriggers::class)->destroyAfterHours($thread))->toBe(168);
});

test('no repository or no key returns null', function () {
    $thread = Thread::factory()->create(['repo' => 'acme/widgets']);
    fakeYakFiles(['config.yml' => "version: 1\n"]);

    expect(app(RefreshTriggers::class)->destroyAfterHours($thread))->toBeNull();

    Repository::factory()->create(['slug' => 'acme/widgets']);

    expect(app(RefreshTriggers::class)->destroyAfterHours($thread))->toBeNull();
});
