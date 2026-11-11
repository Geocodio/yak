<?php

use App\Enums\HeldEventKind;
use App\Enums\ThreadOwnerSide;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Jobs\Threads\StartRepositoryTurnJob;
use App\Models\HeldThreadEvent;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Teleport\HeldEvents;
use App\Services\Threads\PullRequests\RepositoryCiRetry;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    Repository::factory()->create(['slug' => 'acme-web', 'github_full_name' => 'acme/web']);
    $this->thread = Thread::factory()->create(['owner_side' => ThreadOwnerSide::Device]);
    YakTask::factory()->create(['thread_id' => $this->thread->id]);
    $this->web = ThreadRepository::factory()->for($this->thread)->changes()->withPullRequest(12)->create(['repo' => 'acme-web', 'branch' => 'yak/x']);
});

it('holds a sibling CI failure while a device owns the thread', function () {
    app(RepositoryCiRetry::class)->handleResult($this->web, false, 'lint failed', 'abc');

    Queue::assertNotPushed(RetryRepositoryCiJob::class);
    $event = HeldThreadEvent::query()->where('thread_id', $this->thread->id)->sole();
    expect($event->kind)->toBe(HeldEventKind::CiFailed)
        ->and($event->title)->toContain('acme/web#12')
        ->and($event->pull_request_url)->toBe($this->web->pull_request_url);
});

it('holds a sibling comment while a device owns the thread', function () {
    $turns = $this->mock(ThreadTurns::class);
    $turns->shouldNotReceive('start');
    $turns->shouldNotReceive('steer');

    (new StartRepositoryTurnJob($this->web->id, 'Repository: acme/web. Rename it.', 5))->handle($turns, app(HeldEvents::class));

    $event = HeldThreadEvent::query()->where('thread_id', $this->thread->id)->sole();
    expect($event->kind)->toBe(HeldEventKind::ReviewComment)->and($event->title)->toContain('acme/web#12');
});
