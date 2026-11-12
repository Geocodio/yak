<?php

use App\Enums\ThreadKind;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Threads\Preview\ThreadPreviewPanelState;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
});

function panelState(Thread $thread, ?User $viewer = null): array
{
    return app(ThreadPreviewPanelState::class)->build($thread, $viewer ?? User::factory()->create());
}

function previewRunningThread(): Thread
{
    return Thread::factory()->paused()->create(['sandbox_state' => 'running']);
}

it('builds each state from recorded state', function (Closure $make, ?string $devState, string $state) {
    $thread = $make();
    if ($devState !== null) {
        Cache::put("thread-preview:state:{$thread->id}", $devState);
    }

    expect(panelState($thread)['state'])->toBe($state);
})->with([
    'ready' => [fn () => previewRunningThread(), 'ready', 'ready'],
    'asleep paused' => [fn () => Thread::factory()->paused()->create(), null, 'sandbox_asleep'],
    'asleep stopped' => [fn () => Thread::factory()->stopped()->create(), null, 'sandbox_asleep'],
    'waking' => [fn () => Thread::factory()->paused()->create(['sandbox_state' => 'starting']), null, 'waking_sandbox'],
    'starting dev server' => [fn () => previewRunningThread(), 'starting', 'starting_dev_server'],
    'failing' => [fn () => previewRunningThread(), 'failing', 'failing'],
    'no manifest' => [fn () => previewRunningThread(), 'no_manifest', 'no_manifest'],
    'invalid manifest' => [fn () => previewRunningThread(), 'invalid_manifest', 'invalid_manifest'],
    'destroyed' => [fn () => Thread::factory()->destroyed()->create(), null, 'destroyed'],
    'closed' => [fn () => Thread::factory()->paused()->closed()->create(), null, 'closed'],
    'local machine' => [fn () => Thread::factory()->ownedByDevice()->create(), null, 'local_machine'],
    'waiting for slot' => [fn () => Thread::factory()->paused()->create(['sandbox_state' => 'starting', 'blocked_reason' => 'waiting_for_sandbox']), null, 'waiting_for_slot'],
]);

it('gives the url only when the preview is reachable', function () {
    $thread = previewRunningThread();
    Cache::put("thread-preview:state:{$thread->id}", 'ready');
    $ready = panelState($thread);

    expect($ready['url'])->toStartWith('https://t-')->and($ready['host'])->toStartWith('t-');

    expect(panelState(Thread::factory()->destroyed()->create()))->toMatchArray(['url' => null, 'host' => null]);
});

it('carries the config values and the teleport command for a local machine thread', function () {
    config(['yak.threads.preview_safari_fallback' => false, 'yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 4]);
    $thread = Thread::factory()->ownedByDevice()->create();
    $deviceOwner = $thread->ownerDevice->user;

    expect(panelState($thread))->toMatchArray(['teleportCommand' => null]);
    expect(panelState($thread, $deviceOwner))->toMatchArray([
        'safariFallback' => false,
        'awakeCap' => 8,
        'personAwakeCap' => 4,
        'teleportCommand' => "yak teleport {$thread->id}",
    ]);
});

it('lists children with their own sandbox as targets and skips research children', function () {
    $parent = previewRunningThread();
    $implement = Thread::factory()->child($parent)->paused()->create();
    $mockup = Thread::factory()->child($parent)->paused()->create(['kind' => ThreadKind::Mockup]);
    Thread::factory()->child($parent)->create(['kind' => ThreadKind::Research]);
    Thread::factory()->child($parent)->create();

    $targets = panelState($parent)['targets'];

    expect(array_column($targets, 'threadId'))->toBe([$parent->id, $implement->id, $mockup->id])
        ->and($targets[1]['state'])->toBe('sandbox_asleep');
});

it('lets only a viewer who can drive restore a destroyed preview', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->destroyed()->create();
    $guest = User::factory()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);

    expect(panelState($thread, $owner)['canRestore'])->toBeTrue()
        ->and(panelState($thread, $guest)['canRestore'])->toBeFalse()
        ->and(panelState(Thread::factory()->paused()->closed()->create())['canRestore'])->toBeFalse();
});

it('uses the primary repository as the label', function () {
    expect(panelState(Thread::factory()->create(['repo' => 'acme/site']))['repoLabel'])->toBe('acme/site');
});

it('builds preview data only for people who may see the thread', function () {
    $thread = Thread::factory()->private()->create();

    $this->withoutVite()->actingAs(User::factory()->create())->get("/threads/{$thread->id}")->assertNotFound();
});

it('carries the manifest port and cold start command once the dev server has read them', function () {
    $thread = previewRunningThread();

    expect(panelState($thread))->toMatchArray(['port' => null, 'coldStartCommand' => null]);

    Cache::put("thread-preview:manifest:{$thread->id}", ['port' => 5173, 'coldStart' => 'pnpm dev']);

    expect(panelState($thread))->toMatchArray(['port' => 5173, 'coldStartCommand' => 'pnpm dev']);
});
