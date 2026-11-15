<?php

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

function limitPath(Thread $thread): string
{
    return appThreadPath($thread, '/stack/limit');
}

it('updates the limit', function () {
    Queue::fake();
    $world = StackWorld::build();

    $response = $this->patchJson(limitPath($world->root), ['limit' => 3], phoneHeadersFor($world->owner))->assertOk();

    expect($response->json())->toBe(['status' => 'updated', 'limit' => ['current' => 3, 'max' => 4, 'awake' => 1, 'waiting' => 0]])
        ->and($world->root->fresh()->child_awake_limit)->toBe(3);
    ContractFixture::assertMatches('app', 'stack-limit-updated', $response->json());
});

it('answers the same on a repeat', function () {
    Queue::fake();
    $world = StackWorld::build();
    $headers = phoneHeadersFor($world->owner);

    $first = $this->patchJson(limitPath($world->root), ['limit' => 3], $headers)->assertOk();
    $second = $this->patchJson(limitPath($world->root), ['limit' => 3], $headers)->assertOk();

    expect($second->json())->toBe($first->json());
});

it('refuses a limit out of range', function (mixed $limit) {
    $world = StackWorld::build();
    $body = $limit === null ? [] : ['limit' => $limit];

    $response = $this->patchJson(limitPath($world->root), $body, phoneHeadersFor($world->owner))->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    expect($response->json('details.errors.limit'))->not->toBeEmpty();

    if (in_array($limit, [0, 5], true)) {
        expect($response->json('details.errors.limit.0'))->toBe('Choose between 1 and 4.');
    }

    expect($world->root->fresh()->child_awake_limit)->toBe(2);
})->with([0, 5, 'two', null]);

it('answers busy when the lock wait runs out', function () {
    Queue::fake();
    $world = StackWorld::build();
    $busy = BusyRow::on(Thread::class, $world->root);
    $headers = phoneHeadersFor($world->owner);

    $this->patchJson(limitPath($world->root), ['limit' => 3], $headers)->assertStatus(503)->assertJsonPath('error', 'unavailable');
    expect($world->root->fresh()->child_awake_limit)->toBe(2);

    $busy->busy = false;
    $this->patchJson(limitPath($world->root), ['limit' => 3], $headers)->assertOk();
});

it('lets a share recipient read but not change the limit', function () {
    $world = StackWorld::build();

    $this->patchJson(limitPath($world->root), ['limit' => 3], phoneHeadersFor($world->recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect($world->root->fresh()->child_awake_limit)->toBe(2);
});

it('answers a hidden thread like a missing one', function () {
    $world = StackWorld::build();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->patchJson('/api/app/v1/threads/zzzzzzzz/stack/limit', ['limit' => 3], $headers)->assertNotFound();
    $this->patchJson(limitPath($world->root), ['limit' => 3], $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $world = StackWorld::build();
    $path = limitPath($world->root);

    $this->patchJson($path, ['limit' => 3], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $world->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->patchJson($path, ['limit' => 3], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $world->owner->id], isPhone: false);
    $this->patchJson($path, ['limit' => 3], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
