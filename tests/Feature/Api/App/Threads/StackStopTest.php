<?php

use App\Enums\TurnState;
use App\Jobs\StopStackJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BusyRow;
use Tests\Support\ContractFixture;
use Tests\Support\StackWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.threads.person_awake_cap' => 4]);
});

function stopPath(Thread $thread): string
{
    return appThreadPath($thread, '/stack/stop');
}

it('stops the stack', function () {
    Queue::fake();
    $world = StackWorld::build();

    $response = $this->postJson(stopPath($world->root), [], phoneHeadersFor($world->owner))->assertOk()->assertExactJson(['status' => 'stopping']);

    ContractFixture::assertMatches('app', 'stack-stop', $response->json());
    Queue::assertPushed(StopStackJob::class, 1);
});

it('answers stopping again and dispatches nothing once nothing runs', function () {
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);
    $this->postJson(stopPath($world->root), [], $headers)->assertOk();

    Queue::fake();
    $this->postJson(stopPath($world->root), [], $headers)->assertOk()->assertExactJson(['status' => 'stopping']);

    Queue::assertNothingPushed();
});

it('dispatches the job again while a turn still runs', function () {
    Queue::fake();
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);

    $first = $this->postJson(stopPath($world->root), [], $headers)->assertOk();
    $second = $this->postJson(stopPath($world->root), [], $headers)->assertOk();

    expect($second->json())->toBe($first->json());
    Queue::assertPushed(StopStackJob::class, 2);
});

it('stops from a child', function () {
    Queue::fake();
    $world = StackWorld::build();

    $this->postJson(stopPath($world->working), [], phoneHeadersFor($world->owner))->assertOk();

    expect($world->root->fresh()->stack_stopped_at)->not->toBeNull();
});

it('shows the stopped stack', function () {
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);
    $this->postJson(stopPath($world->root), [], $headers)->assertOk();
    expect(TaskRun::query()->where('turn_state', TurnState::Running)->exists())->toBeFalse();

    $response = $this->getJson(appThreadPath($world->root, '/stack'), $headers)->assertOk()
        ->assertJsonPath('canStop', false)
        ->assertJsonPath('addChild', ['allowed' => false, 'error' => 'stack_stopped', 'message' => 'This stack was stopped, so it cannot start sub-threads.']);

    expect($response->json('stoppedAt'))->not->toBeNull();
    ContractFixture::assertMatches('app', 'stack-stopped', $response->json(), ['rootId', 'children.*.id', 'messages.*.id']);
});

it('answers busy when the lock wait runs out', function () {
    Queue::fake();
    $world = StackWorld::build();
    $busy = BusyRow::on(Thread::class, $world->root);
    $headers = phoneHeadersFor($world->owner);

    $this->postJson(stopPath($world->root), [], $headers)->assertStatus(503)->assertExactJson([
        'error' => 'unavailable',
        'message' => 'Yak is still handling another request for this thread. Try again in a moment.',
    ]);
    expect($world->root->fresh()->stack_stopped_at)->toBeNull();

    $busy->busy = false;
    $this->postJson(stopPath($world->root), [], $headers)->assertOk();
});

it('lets a share recipient read but not stop', function () {
    Queue::fake();
    $world = StackWorld::build();

    $this->postJson(stopPath($world->root), [], phoneHeadersFor($world->recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect($world->root->fresh()->stack_stopped_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('answers a hidden thread like a missing one', function () {
    $world = StackWorld::build();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/stack/stop', [], $headers)->assertNotFound();
    $this->postJson(stopPath($world->root), [], $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson(stopPath($world->failed), [], $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $world = StackWorld::build();
    $path = stopPath($world->root);

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $world->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $world->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
