<?php

use App\Models\Thread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;
use Tests\Support\StackWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.threads.person_awake_cap' => 4]);
});

it('shows a busy stack to its owner', function () {
    $world = StackWorld::build();

    $response = $this->getJson(appThreadPath($world->root, '/stack'), phoneHeadersFor($world->owner))->assertOk()
        ->assertJsonPath('children.0.state', 'working')
        ->assertJsonPath('children.1.state', 'failed')
        ->assertJsonPath('children.2.state', 'done')
        ->assertJsonPath('children.0.canOverride', false)
        ->assertJsonPath('children.1.canOverride', true)
        ->assertJsonPath('children.2.canOverride', false)
        ->assertJsonPath('limit', ['current' => 2, 'max' => 4, 'awake' => 1, 'waiting' => 0])
        ->assertJsonPath('stoppedAt', null)
        ->assertJsonPath('addChild.allowed', true)
        ->assertJsonPath('usage.turnCount', 7)
        ->assertJsonPath('usage.costUsd', 1.25)
        ->assertJsonPath('usage.activeHours', 1.5)
        ->assertJsonCount(2, 'messages');

    ContractFixture::assertMatches('app', 'stack', $response->json(), ['rootId', 'children.*.id', 'messages.*.id']);
});

it('shows the stack from a child', function () {
    $world = StackWorld::build();

    $response = $this->getJson(appThreadPath($world->failed, '/stack'), phoneHeadersFor($world->owner))->assertOk()
        ->assertJsonPath('isRoot', false)
        ->assertJsonPath('parent.id', $world->root->public_id)
        ->assertJsonPath('rootId', $world->root->public_id)
        ->assertJsonPath('addChild.allowed', false)
        ->assertJsonPath('addChild.error', 'sub_thread_cannot_spawn');

    ContractFixture::assertMatches('app', 'stack-child', $response->json(), ['rootId', 'parent.id', 'children.*.id', 'messages.*.id']);
});

it('shows a root with no sub-threads', function () {
    $owner = User::factory()->create(['name' => 'Test person']);
    $root = privateThreadOf($owner, ['title' => 'Move search to the new index', 'name' => 'move-search', 'repo' => 'example/api']);

    // A cost of zero encodes as the JSON number 0, not 0.0.
    $response = $this->getJson(appThreadPath($root, '/stack'), phoneHeadersFor($owner))->assertOk()
        ->assertJsonPath('children', [])
        ->assertJsonPath('messages', [])
        ->assertJsonPath('usage.turnCount', 0)
        ->assertJsonPath('addChild.allowed', true);

    ContractFixture::assertMatches('app', 'stack-empty', $response->json(), ['rootId']);
});

it('lets a share recipient read but not drive', function () {
    $world = StackWorld::build();

    $this->getJson(appThreadPath($world->root, '/stack'), phoneHeadersFor($world->recipient))->assertOk()
        ->assertJsonPath('canDrive', false)
        ->assertJsonPath('canStop', false)
        ->assertJsonPath('children.1.canOverride', false)
        ->assertJsonPath('addChild.allowed', false)
        ->assertJsonPath('addChild.error', 'forbidden');
});

it('answers a hidden thread like a missing one', function () {
    $world = StackWorld::build();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->getJson('/api/app/v1/threads/zzzzzzzz/stack', $headers)->assertNotFound();
    $this->getJson(appThreadPath($world->root, '/stack'), $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->getJson(appThreadPath($world->failed, '/stack'), $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $world = StackWorld::build();
    $path = appThreadPath($world->root, '/stack');

    $this->getJson($path, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $world->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson($path, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $world->owner->id], isPhone: false);
    $this->getJson($path, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('shows a working child its own step', function () {
    $world = StackWorld::build();

    expect(Thread::query()->where('root_thread_id', $world->root->id)->count())->toBe(3);
    $this->getJson(appThreadPath($world->working, '/stack'), phoneHeadersFor($world->owner))->assertOk()->assertJsonPath('children.0.step', 'Writing the migration');
});
