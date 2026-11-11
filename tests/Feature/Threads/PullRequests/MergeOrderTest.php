<?php

use App\Jobs\Threads\RefreshSiblingBlocksJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\PullRequests\MergeOrder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function orderedThread(): array
{
    $thread = Thread::factory()->create();
    $a = ThreadRepository::factory()->for($thread)->withPullRequest(1)->create(['repo' => 'acme/a']);
    $b = ThreadRepository::factory()->for($thread)->changes()->withPullRequest(2)->create(['repo' => 'acme/b']);
    $c = ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/c']);
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/d']);

    return [$thread, $a, $b, $c];
}

it('defaults to open order and skips rows without a pull request', function () {
    [$thread, $a, $b] = orderedThread();
    $order = app(MergeOrder::class);

    $order->assignDefault($thread);

    expect($order->forThread($thread)->pluck('id')->all())->toBe([$a->id, $b->id])
        ->and($a->refresh()->merge_position)->toBe(1)->and($b->refresh()->merge_position)->toBe(2);
});

it('rejects missing, duplicate or foreign ids without writing', function (array $ids) {
    Queue::fake();
    [$thread, $a, $b] = orderedThread();
    $foreign = ThreadRepository::factory()->withPullRequest(5)->create()->id;
    $ids = array_map(fn ($id) => match ($id) {
        'a' => $a->id, 'b' => $b->id, default => $foreign
    }, $ids);

    expect(fn () => app(MergeOrder::class)->set($thread, $ids))->toThrow(ValidationException::class);
    expect($a->refresh()->merge_position)->toBeNull();
    Queue::assertNothingPushed();
})->with([[['a']], [['a', 'a']], [['a', 'x']]]);

it('rewrites positions and dispatches the refresh job', function () {
    Queue::fake();
    [$thread, $a, $b] = orderedThread();

    app(MergeOrder::class)->set($thread, [$b->id, $a->id]);

    expect($b->refresh()->merge_position)->toBe(1)->and($a->refresh()->merge_position)->toBe(2);
    Queue::assertPushed(RefreshSiblingBlocksJob::class, fn ($job) => $job->threadId === $thread->id);
});
