<?php

use App\Jobs\DeliverStackNoteJob;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadSandbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BusyRow;
use Tests\Support\ContractFixture;
use Tests\Support\StackWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.threads.person_awake_cap' => 4]);
    $sandbox = $this->mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy');
});

function overridePath(Thread $thread, string $action): string
{
    return appThreadPath($thread, "/stack/{$action}");
}

it('takes over a failed sub-thread', function () {
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);

    $first = $this->postJson(overridePath($world->failed, 'take-over'), [], $headers)->assertOk()->assertExactJson(['status' => 'taken_over']);
    $this->postJson(overridePath($world->failed, 'take-over'), [], $headers)->assertOk()->assertExactJson(['status' => 'taken_over']);

    $failed = collect($this->getJson(appThreadPath($world->root, '/stack'), $headers)->json('children'))->firstWhere('name', 'query-adapter');
    expect($failed['takenOver'])->toBeTrue()->and($failed['canOverride'])->toBeFalse();
    ContractFixture::assertMatches('app', 'stack-take-over', $first->json());
});

it('asks the parent to split a failed sub-thread', function () {
    Queue::fake();
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);

    $first = $this->postJson(overridePath($world->failed, 'split'), [], $headers)->assertOk()->assertExactJson(['status' => 'split_requested']);
    $this->postJson(overridePath($world->failed, 'split'), [], $headers)->assertOk()->assertExactJson(['status' => 'split_requested']);

    expect(StackThreadMessage::query()->whereNotNull('from_user_id')->count())->toBe(1);
    ContractFixture::assertMatches('app', 'stack-split', $first->json());
});

it('drops a failed sub-thread', function () {
    Queue::fake();
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);

    $first = $this->postJson(overridePath($world->failed, 'drop'), [], $headers)->assertOk()->assertExactJson(['status' => 'dropped']);
    $this->postJson(overridePath($world->failed, 'drop'), [], $headers)->assertOk()->assertExactJson(['status' => 'dropped']);

    expect($world->failed->fresh()->isOpen())->toBeFalse()
        ->and(StackThreadMessage::query()->whereNotNull('from_user_id')->count())->toBe(1);
    // The first job never ran, so the repeat queues the same note again.
    expect(Queue::pushed(DeliverStackNoteJob::class)->pluck('messageId')->unique())->toHaveCount(1);
    ContractFixture::assertMatches('app', 'stack-drop', $first->json());
});

it('refuses a working sub-thread', function (string $action) {
    $world = StackWorld::build();

    $this->postJson(overridePath($world->working, $action), [], phoneHeadersFor($world->owner))->assertStatus(409)->assertExactJson([
        'error' => 'child_not_failed',
        'message' => 'Only a sub-thread that failed can be taken over, split or dropped.',
        'details' => ['state' => 'working'],
    ]);
})->with(['take-over', 'split', 'drop']);

it('answers 404 on a root', function (string $action) {
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);

    $missing = $this->postJson("/api/app/v1/threads/zzzzzzzz/stack/{$action}", [], $headers)->assertNotFound();
    $this->postJson(overridePath($world->root, $action), [], $headers)->assertNotFound()->assertExactJson($missing->json());
})->with(['take-over', 'split', 'drop']);

it('answers busy when the lock wait runs out', function (string $action) {
    Queue::fake();
    $world = StackWorld::build();
    $busy = BusyRow::on(Thread::class, $world->root);
    $headers = phoneHeadersFor($world->owner);

    $this->postJson(overridePath($world->failed, $action), [], $headers)->assertStatus(503)->assertJsonPath('error', 'unavailable');
    expect(StackThreadMessage::query()->whereNotNull('from_user_id')->exists())->toBeFalse();

    $busy->busy = false;
    $this->postJson(overridePath($world->failed, $action), [], $headers)->assertOk();
})->with(['take-over', 'split', 'drop']);

it('lets a share recipient read but not override', function (string $action) {
    Queue::fake();
    $world = StackWorld::build();

    $this->postJson(overridePath($world->failed, $action), [], phoneHeadersFor($world->recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect($world->failed->fresh()->taken_over_at)->toBeNull()
        ->and($world->failed->fresh()->isOpen())->toBeTrue();
    Queue::assertNothingPushed();
})->with(['take-over', 'split', 'drop']);

it('answers a hidden thread like a missing one', function (string $action) {
    $world = StackWorld::build();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson("/api/app/v1/threads/zzzzzzzz/stack/{$action}", [], $headers)->assertNotFound();
    $this->postJson(overridePath($world->failed, $action), [], $headers)->assertNotFound()->assertExactJson($missing->json());
})->with(['take-over', 'split', 'drop']);

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $world = StackWorld::build();
    $path = overridePath($world->failed, 'drop');

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $world->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $world->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
