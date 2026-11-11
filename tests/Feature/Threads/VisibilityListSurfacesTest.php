<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadListFilter;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\CoOwnerRequest;
use App\Models\PrReview;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;
use App\Services\Threads\ThreadNotificationGuard;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Support\VisibilityRoutes;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    $this->withoutVite();
    $this->world = VisibilityWorld::build();
    $this->teamThread = Thread::factory()->create(['title' => 'Visible team thread', 'blocked_reason' => ThreadBlockReason::UsageLimit]);
    $this->world->thread->update(['blocked_reason' => ThreadBlockReason::UsageLimit]);
    $this->world->child->update(['blocked_reason' => ThreadBlockReason::UsageLimit]);
});

/** Removes the private graph, leaving only the team thread. */
function removePrivateGraph(VisibilityWorld $world): void
{
    Schema::disableForeignKeyConstraints();
    foreach ([CoOwnerRequest::class, PrReview::class, Artifact::class, TaskAttachment::class, BranchDeployment::class, TaskRun::class, YakTask::class] as $model) {
        $model::withoutGlobalScopes()->whereIn($model === CoOwnerRequest::class ? 'task_id' : ($model === YakTask::class ? 'id' : 'yak_task_id'), [$world->task->id, $world->childTask->id])->delete();
    }
    Thread::withoutGlobalScopes()->whereIn('id', [$world->thread->id, $world->child->id])->delete();
    Schema::enableForeignKeyConstraints();
}

function inertiaProps($test, string $uri, $user): array
{
    $response = $test->actingAs($user)->get($uri);

    if (str_contains((string) $response->headers->get('Content-Type'), 'json')) {
        return $response->json();
    }

    return $response->getStatusCode() === 200 ? AssertableInertia::fromTestResponse($response)->toArray()['props'] : ['status' => $response->getStatusCode()];
}

it('leaves the thread list, search and needs-you count free of a private thread', function (string $stranger, string $filter, ?string $search) {
    $list = ThreadList::for($this->world->{$stranger}, ThreadListFilter::from($filter), $search);
    $encoded = json_encode($list);

    expect($encoded)->not->toContain(VisibilityWorld::TITLE)
        ->and($encoded)->not->toContain(VisibilityWorld::REPO)
        ->and($list['needsYouCount'])->toBe($filter === 'everyone' && $search === null ? 1 : 0);
})->with(['other', 'admin'])->with(['mine', 'everyone'])->with([null, 'Quarterly', 'payroll', 'layoff']);

it('shows the owner the private thread with its child and counts it', function () {
    $list = ThreadList::for($this->world->owner, ThreadListFilter::Everyone);

    expect(collect([...$list['needsYou'], ...$list['threads']])->pluck('id')->all())->toContain($this->world->thread->id)
        ->and(collect($list['needsYou'])->firstWhere('id', $this->world->thread->id)['children'])->toHaveCount(1)
        ->and($list['needsYouCount'])->toBe(2);

    expect(json_encode(ThreadList::for($this->world->owner, ThreadListFilter::Everyone, 'Quarterly')))->toContain(VisibilityWorld::TITLE);
});

it('renders every list surface for a stranger as if the private graph did not exist', function (string $name, string $stranger) {
    $route = VisibilityRoutes::applicationRoutes()[$name];
    $uri = '/' . ltrim(str_replace(['{path?}', '{path}'], '', $route->uri()), '/');
    $user = $this->world->{$stranger};

    $before = inertiaProps($this, $uri, $user);
    expect(json_encode($before))->not->toContain(VisibilityWorld::TITLE)
        ->and(json_encode($before))->not->toContain(VisibilityWorld::BRANCH);

    removePrivateGraph($this->world);

    expect(inertiaProps($this, $uri, $user))->toEqual($before);
})->with(function () {
    foreach (VisibilityRoutes::lists() as $name) {
        foreach (['other', 'admin'] as $stranger) {
            yield "{$name} as {$stranger}" => [$name, $stranger];
        }
    }
});

it('keeps a private thread and its child out of notification recipients', function () {
    $recipients = fn (Thread $thread) => array_map(
        fn ($user) => $user->id,
        app(ThreadNotificationGuard::class)->recipientsFor($thread, [$this->world->owner, $this->world->other, $this->world->admin]),
    );

    expect($recipients($this->world->thread))->toBe([$this->world->owner->id])
        ->and($recipients($this->world->child))->toBe([$this->world->owner->id])
        ->and($recipients($this->teamThread))->toHaveCount(3);
});

it('leaves the stack tab data for the wave 3 integration', function () {
    if (! class_exists('App\\Http\\Resources\\StackTabData')) {
        $this->markTestSkipped('App\\Http\\Resources\\StackTabData has not landed; the integrator adds its row here.');
    }
});
