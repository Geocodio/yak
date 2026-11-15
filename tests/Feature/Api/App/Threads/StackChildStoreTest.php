<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Exceptions\StackRuleViolation;
use App\Jobs\StartChildThreadJob;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\ChildSpawner;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;
use Tests\Support\StackWorld;

const ADD_KEY = '3f6d2c1a-5b7e-4d89-a1c2-9e8f7a6b5c4d';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.threads.person_awake_cap' => 4]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addBody(array $overrides = []): array
{
    return ['name' => 'scout', 'brief' => 'Look around.', 'kind' => 'research', 'send_key' => ADD_KEY, ...$overrides];
}

function addPath(Thread $thread): string
{
    return appThreadPath($thread, '/stack/children');
}

dataset('refused stack states', [
    'a sub-thread' => [fn (StackWorld $world): Thread => $world->failed, 'sub_thread_cannot_spawn', 'A sub-thread cannot start sub-threads of its own. Add it to the parent thread instead.'],
    'a closed thread' => [function (StackWorld $world): Thread {
        $world->root->forceFill(['status' => ThreadStatus::Closed, 'closed_at' => now(), 'close_reason' => 'merged'])->save();

        return $world->root;
    }, 'thread_closed', 'This thread is closed, so it cannot start sub-threads.'],
    'a thread on a computer' => [function (StackWorld $world): Thread {
        $world->root->forceFill(['owner_side' => ThreadOwnerSide::Device])->save();

        return $world->root;
    }, 'thread_owned_by_device', 'Your computer is working on this thread. Hand it back to Yak before adding a sub-thread.'],
    'a stopped stack' => [function (StackWorld $world): Thread {
        $world->root->forceFill(['stack_stopped_at' => now()])->save();

        return $world->root;
    }, 'stack_stopped', 'This stack was stopped, so it cannot start sub-threads.'],
]);

it('adds a sub-thread and answers its public id', function () {
    Queue::fake();
    $world = StackWorld::build();

    $response = $this->postJson(addPath($world->root), addBody(['name' => 'Backfill job', 'brief' => 'Backfill the new index in batches of 500.', 'kind' => 'implement', 'model' => 'sonnet']), phoneHeadersFor($world->owner))->assertCreated();

    $child = Thread::query()->where('public_id', $response->json('id'))->sole();
    expect($response->json('name'))->toBe('backfill-job-2')
        ->and($child->spawn_key)->toBe(ADD_KEY)
        ->and($child->parent_thread_id)->toBe($world->root->id);
    ContractFixture::assertMatches('app', 'stack-child-created', $response->json(), ['id']);
});

it('answers the same body for a repeated send key', function () {
    Queue::fake();
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);

    $first = $this->postJson(addPath($world->root), addBody(), $headers)->assertCreated();
    $second = $this->postJson(addPath($world->root), addBody(), $headers)->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and(Thread::query()->where('spawn_key', ADD_KEY)->count())->toBe(1)
        ->and(Thread::query()->where('parent_thread_id', $world->root->id)->count())->toBe(4);
    // The first start job is still pending, so the repeat adds no second one.
    Queue::assertPushed(StartChildThreadJob::class, 1);
});

it('refuses at the cap with the count', function () {
    Queue::fake();
    $owner = User::factory()->create(['name' => 'Test person']);
    $root = privateThreadOf($owner, ['title' => 'Move search to the new index', 'name' => 'move-search', 'repo' => 'example/api']);
    Thread::factory()->child($root)->count(12)->create();

    $response = $this->postJson(addPath($root), addBody(), phoneHeadersFor($owner))->assertStatus(409)->assertExactJson([
        'error' => 'stack_full',
        'message' => 'This thread has started 12 of 12 sub-threads, the most one thread can have.',
        'details' => ['count' => 12, 'max' => 12],
    ]);

    expect(Thread::query()->where('parent_thread_id', $root->id)->count())->toBe(12);
    ContractFixture::assertMatches('app', 'error-stack-full', $response->json());
});

it('refuses every rule with its code', function (Closure $prepare, string $error, string $message) {
    Queue::fake();
    $world = StackWorld::build();
    $thread = $prepare($world);
    $before = Thread::query()->count();

    $response = $this->postJson(addPath($thread), addBody(), phoneHeadersFor($world->owner))->assertStatus(409)
        ->assertJsonPath('error', $error)
        ->assertJsonPath('message', $message);

    if ($error === 'sub_thread_cannot_spawn') {
        $response->assertJsonPath('details.parentId', $world->root->public_id);
    }

    expect(Thread::query()->count())->toBe($before);
    Queue::assertNothingPushed();
})->with('refused stack states');

it('agrees with the stack about adding', function (Closure $prepare, string $error, string $message) {
    Queue::fake();
    $world = StackWorld::build();
    $thread = $prepare($world);
    $headers = phoneHeadersFor($world->owner);

    $stack = $this->getJson(appThreadPath($thread, '/stack'), $headers)->assertOk()->json('addChild');
    $post = $this->postJson(addPath($thread), addBody(), $headers)->assertStatus(409);

    expect($stack['allowed'])->toBeFalse()
        ->and($stack['error'])->toBe($post->json('error'))
        ->and($stack['message'])->toBe($post->json('message'));
})->with('refused stack states');

it('agrees with the stack for a share recipient and an open root', function () {
    Queue::fake();
    $world = StackWorld::build();
    $recipient = phoneHeadersFor($world->recipient);

    $stack = $this->getJson(appThreadPath($world->root, '/stack'), $recipient)->assertOk()->json('addChild');
    $post = $this->postJson(addPath($world->root), addBody(), $recipient)->assertForbidden();
    expect($stack['allowed'])->toBeFalse()
        ->and($stack['error'])->toBe($post->json('error'))
        ->and($stack['message'])->toBe($post->json('message'));

    $owner = phoneHeadersFor($world->owner);
    $this->getJson(appThreadPath($world->root, '/stack'), $owner)->assertOk()->assertJsonPath('addChild.allowed', true);
    $this->postJson(addPath($world->root), addBody(), $owner)->assertCreated();
});

it('validates the add', function (array $overrides, string $field) {
    Queue::fake();
    $world = StackWorld::build();

    $body = addBody($overrides);
    if ($overrides === ['send_key' => null]) {
        unset($body['send_key']);
    }

    $this->postJson(addPath($world->root), $body, phoneHeadersFor($world->owner))->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['details' => ['errors' => [$field]]]);
    Queue::assertNothingPushed();
})->with([
    'a missing send key' => [['send_key' => null], 'send_key'],
    'a send key that is not a uuid' => [['send_key' => 'abc'], 'send_key'],
    'a blank name' => [['name' => '   '], 'name'],
    'a long name' => [['name' => str_repeat('a', 61)], 'name'],
    'a blank brief' => [['brief' => ''], 'brief'],
    'a long brief' => [['brief' => str_repeat('a', 20001)], 'brief'],
    'a kind that is not allowed' => [['kind' => 'deploy'], 'kind'],
    'a long model' => [['model' => str_repeat('a', 101)], 'model'],
    'an unknown model' => [['model' => 'gpt-9'], 'model'],
]);

it('answers 422 when the spawner refuses input the request accepted', function () {
    $world = StackWorld::build();
    $this->mock(ChildSpawner::class)->shouldReceive('spawn')->andThrow(new StackRuleViolation('Unknown model.', personMessage: 'Choose one of the listed models.', field: 'model'));

    $this->postJson(addPath($world->root), addBody(), phoneHeadersFor($world->owner))->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonPath('details.errors.model.0', 'Choose one of the listed models.');
});

it('answers busy when the lock wait runs out', function () {
    Queue::fake();
    $world = StackWorld::build();
    $busy = true;
    Thread::retrieved(function (Thread $model) use ($world, &$busy): void {
        if ($busy && $model->is($world->root) && DB::transactionLevel() > 1) {
            $exception = new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded'));
            $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded'];

            throw $exception;
        }
    });
    $headers = phoneHeadersFor($world->owner);

    $this->postJson(addPath($world->root), addBody(), $headers)->assertStatus(503)->assertExactJson([
        'error' => 'unavailable',
        'message' => 'Yak is still handling another request for this thread. Try again in a moment.',
    ]);
    expect(Thread::query()->where('spawn_key', ADD_KEY)->exists())->toBeFalse();

    $busy = false;
    $this->postJson(addPath($world->root), addBody(), $headers)->assertCreated();
});

it('lets a share recipient read but not add', function () {
    Queue::fake();
    $world = StackWorld::build();

    $this->postJson(addPath($world->root), addBody(), phoneHeadersFor($world->recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect(Thread::query()->where('spawn_key', ADD_KEY)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('answers a hidden thread like a missing one', function () {
    $world = StackWorld::build();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/stack/children', addBody(), $headers)->assertNotFound();
    $this->postJson(addPath($world->root), addBody(), $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson(addPath($world->failed), addBody(), $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $world = StackWorld::build();
    $path = addPath($world->root);

    $this->postJson($path, addBody(), ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $world->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, addBody(), $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $world->owner->id], isPhone: false);
    $this->postJson($path, addBody(), $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
