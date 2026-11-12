<?php

use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Push\PullRequestPush;
use Tests\Support\FakePushEndpoint;

beforeEach(function () {
    $this->push = FakePushEndpoint::install();
    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->create(['owner_user_id' => $this->owner->id]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id]);
    $this->device = $this->push->subscription($this->owner);
});

it('pushes the owner for opened, ci failed and ci passed', function (string $method) {
    $this->push->respondWith();

    app(PullRequestPush::class)->{$method}($this->task);

    $this->push->assertSentTo($this->device->endpoint);
})->with(['opened', 'ciFailed', 'ciPassed']);

it('pushes nothing for a task without a thread', function () {
    app(PullRequestPush::class)->opened(YakTask::factory()->create(['thread_id' => null]));

    $this->push->assertNothingSent();
});

it('leaves a device-owned thread to held events', function () {
    $thread = Thread::factory()->ownedByDevice()->create(['owner_user_id' => $this->owner->id]);

    app(PullRequestPush::class)->ciFailed(YakTask::factory()->create(['thread_id' => $thread->id]));

    $this->push->assertNothingSent();
});

it('keeps a private thread generic', function () {
    $thread = Thread::factory()->private($this->owner)->create(['title' => 'Secret plan']);
    $this->push->respondWith();

    app(PullRequestPush::class)->opened(YakTask::factory()->create(['thread_id' => $thread->id]));

    expect($this->push->requests())->toHaveCount(1)
        ->and((string) $this->push->requests()[0]->getBody())->not->toContain('Secret plan');
});
