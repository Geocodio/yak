<?php

use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;

dataset('thread form requests', [
    'reorder merge order' => ['put', 'threads.pull-requests.order', ['order' => 'nope']],
    'send message' => ['post', 'threads.messages.store', ['message' => '']],
    'store stack child' => ['post', 'threads.stack.children.store', ['name' => '']],
    'update stack limit' => ['patch', 'threads.stack.limit', ['limit' => 'nope']],
    'open pull request' => ['post', 'threads.pull-request.store', ['acknowledged_private_publish' => 'nope']],
    'teleport request' => ['post', 'threads.teleport-request.store', ['acknowledged_private_publish' => 'nope']],
]);

it('answers a hidden thread with invalid input exactly like a missing thread', function (string $method, string $routeName, array $payload) {
    config(['app.debug' => false]);
    $hidden = Thread::factory()->private(User::factory()->create())->create();
    $viewer = User::factory()->create();

    $missing = $this->actingAs($viewer)->json($method, route($routeName, ['thread' => 'zzzzzzzz']), $payload);
    $response = $this->actingAs($viewer)->json($method, route($routeName, $hidden), $payload);

    expect($response->status())->toBe(404)
        ->and($missing->status())->toBe(404)
        ->and(str_replace($hidden->public_id, 'ID', (string) $response->getContent()))
        ->toBe(str_replace('zzzzzzzz', 'ID', (string) $missing->getContent()));
})->with('thread form requests');

it('refuses a read-only viewer before validating', function (string $method, string $routeName, array $payload) {
    $thread = Thread::factory()->private($owner = User::factory()->create())->create();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->json($method, route($routeName, $thread), $payload)->assertForbidden();
})->with('thread form requests');

it('still validates for the owner', function (string $method, string $routeName, array $payload) {
    $thread = Thread::factory()->private($owner = User::factory()->create())->create();

    $this->actingAs($owner)->json($method, route($routeName, $thread), $payload)->assertStatus(422);
})->with('thread form requests');

it('applies the same ordering to the owner-only share update', function () {
    $payload = ['mode' => 'nope'];
    $thread = Thread::factory()->private($owner = User::factory()->create())->create();
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->putJson(route('threads.share.update', $thread), $payload)->assertForbidden();
    $this->actingAs($stranger)->putJson(route('threads.share.update', $thread), $payload)->assertNotFound();
    $this->actingAs($owner)->putJson(route('threads.share.update', $thread), $payload)->assertStatus(422);
});
