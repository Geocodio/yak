<?php

use App\DataTransferObjects\YakToolCall;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\YakToolRouter;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->thread = Thread::factory()->create(['repo' => 'acme/app']);
    $this->app_row = ThreadRepository::factory()->withPullRequest(1)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/app']);
    $this->lib_row = ThreadRepository::factory()->changes()->withPullRequest(2)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/lib']);
});

function setMergeOrder(Thread $thread, array $repos)
{
    return app(YakToolRouter::class)->handle($thread, new YakToolCall('call-1', 'set_merge_order', ['repos' => $repos]));
}

it('sets the merge order from repository slugs, case-insensitively', function () {
    $result = setMergeOrder($this->thread, ['acme/lib', 'ACME/app']);

    expect($result->isError)->toBeFalse()
        ->and($result->content)->toBe('Merge order set: acme/lib, acme/app.')
        ->and($this->lib_row->fresh()->merge_position)->toBe(1)
        ->and($this->app_row->fresh()->merge_position)->toBe(2);
});

it('returns an error for a slug without a pull request', function () {
    $result = setMergeOrder($this->thread, ['acme/lib', 'acme/nope']);

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toContain('acme/nope')
        ->and($this->lib_row->fresh()->merge_position)->toBeNull();
});

it('returns an error when a repository is omitted', function () {
    $result = setMergeOrder($this->thread, ['acme/lib']);

    expect($result->isError)->toBeTrue()
        ->and($this->lib_row->fresh()->merge_position)->toBeNull()
        ->and($this->app_row->fresh()->merge_position)->toBeNull();
});

it('orders the rows of fan-out children from the parent thread', function () {
    $child = Thread::factory()->create(['repo' => 'acme/web', 'root_thread_id' => $this->thread->id, 'parent_thread_id' => $this->thread->id]);
    $web = ThreadRepository::factory()->withPullRequest(3)->create(['thread_id' => $child->id, 'repo' => 'acme/web']);

    $result = setMergeOrder($this->thread, ['acme/web', 'acme/app', 'acme/lib']);

    expect($result->isError)->toBeFalse()
        ->and($web->fresh()->merge_position)->toBe(1)
        ->and($this->app_row->fresh()->merge_position)->toBe(2)
        ->and($this->lib_row->fresh()->merge_position)->toBe(3);
});
