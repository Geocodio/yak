<?php

use App\Events\StackThresholdReached;
use App\Mail\HeldEventMail;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\StackUsagePush;
use App\Services\Stacks\StackUsage;
use App\Services\Stacks\StackUsageAlerts;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Event::fake([StackThresholdReached::class]);
    $this->owner = User::factory()->create();
    $this->root = Thread::factory()->private($this->owner)->create(['title' => 'Secret migration']);
});

function stackRuns(Thread $thread, int $count, array $attributes = []): void
{
    TaskRun::factory()->count($count)->create(['thread_id' => $thread->id, 'recorded_cost_usd' => 0.5, ...$attributes]);
}

it('alerts at 50 turns once', function () {
    stackRuns($this->root, 50);

    expect(app(StackUsageAlerts::class)->check($this->root))->toHaveCount(1);
    Mail::assertSent(HeldEventMail::class, 1);
    Event::assertDispatched(StackThresholdReached::class, fn ($event) => $event->threshold === 'turns' && $event->mark === 1);
});

it('alerts after 3 hours of activity', function () {
    stackRuns($this->root, 1, ['started_at' => now()->subHours(4), 'finished_at' => null]);

    expect(app(StackUsageAlerts::class)->check($this->root))->toHaveCount(1);
    Event::assertDispatched(StackThresholdReached::class, fn ($event) => $event->threshold === 'hours');
});

it('alerts at 12 children', function () {
    Thread::factory()->child($this->root)->count(12)->create();

    app(StackUsageAlerts::class)->check($this->root);

    Event::assertDispatched(StackThresholdReached::class, fn ($event) => $event->threshold === 'children');
});

it('does not alert twice for the same mark', function () {
    stackRuns($this->root, 50);
    $alerts = app(StackUsageAlerts::class);

    $alerts->check($this->root->fresh());

    expect($alerts->check($this->root->fresh()))->toBe([]);
    Mail::assertSent(HeldEventMail::class, 1);

    stackRuns($this->root, 50);
    expect($alerts->check($this->root->fresh()))->toHaveCount(1);
});

it('uses a generic title for a private stack', function () {
    stackRuns($this->root, 50);

    app(StackUsageAlerts::class)->check($this->root);

    Mail::assertSent(HeldEventMail::class, fn (HeldEventMail $mail) => ! str_contains($mail->notice, 'Secret migration') && ! str_contains($mail->notice, 'turns'));
});

it('does not stop any run', function () {
    stackRuns($this->root, 50, ['finished_at' => null]);
    $before = TaskRun::query()->whereNull('finished_at')->count();

    app(StackUsageAlerts::class)->check($this->root);

    expect(TaskRun::query()->whereNull('finished_at')->count())->toBe($before);
});

it('sums turns, cost and children across the stack', function () {
    $child = Thread::factory()->child($this->root)->create();
    stackRuns($this->root, 2);
    stackRuns($child, 3);

    expect(app(StackUsage::class)->for($this->root))->toMatchArray(['turnCount' => 5, 'costUsd' => 2.5, 'childCount' => 1]);
});

it('sends a stack usage web push when a mark is crossed', function () {
    stackRuns($this->root, 50);
    $this->mock(StackUsagePush::class)->shouldReceive('send')->once()->with(Mockery::on(fn ($thread) => $thread->is($this->root)), 'its stack has run 50 turns. Nothing was paused.')->andReturn(1);

    app(StackUsageAlerts::class)->check($this->root);
});

it('does not push again for the same mark', function () {
    stackRuns($this->root, 50);
    $this->mock(StackUsagePush::class)->shouldReceive('send')->once()->andReturn(1);
    $alerts = app(StackUsageAlerts::class);

    $alerts->check($this->root->fresh());
    $alerts->check($this->root->fresh());
});

it('still notifies and dispatches when the push throws', function () {
    stackRuns($this->root, 50);
    $this->mock(StackUsagePush::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('push down'));

    expect(app(StackUsageAlerts::class)->check($this->root))->toHaveCount(1);
    Mail::assertSent(HeldEventMail::class, 1);
    Event::assertDispatched(StackThresholdReached::class);
});
