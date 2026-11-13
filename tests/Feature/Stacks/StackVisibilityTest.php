<?php

use App\DataTransferObjects\YakToolCall;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\StackToolRouter;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    $this->withoutVite();
});

dataset('stackRoutes', [
    'threads.stack.show' => ['GET', '/threads/{thread}/stack', []],
    'threads.stack.limit' => ['PATCH', '/threads/{thread}/stack/limit', ['limit' => 2]],
    'threads.stack.stop' => ['POST', '/threads/{thread}/stack/stop', []],
    'threads.stack.children.store' => ['POST', '/threads/{thread}/stack/children', ['name' => 'scout', 'brief' => 'Look.', 'kind' => 'research']],
    'threads.stack.take-over' => ['POST', '/threads/{child}/stack/take-over', []],
    'threads.stack.split' => ['POST', '/threads/{child}/stack/split', []],
    'threads.stack.drop' => ['POST', '/threads/{child}/stack/drop', []],
    'threads.stack.children.diff' => ['GET', '/threads/{thread}/stack/children/{child}/diff', []],
]);

it('answers a private stack route like a missing thread and writes nothing', function (string $method, string $uri, array $payload, string $stranger) {
    $world = VisibilityWorld::build();
    $hiddenUri = strtr($uri, ['{thread}' => $world->thread->id, '{child}' => $world->child->id]);
    $missingUri = strtr($uri, ['{thread}' => $world->missingId, '{child}' => $world->missingId]);
    $before = Thread::query()->withoutGlobalScopes()->count();

    $missing = $this->actingAs($world->{$stranger})->call($method, $missingUri, $payload);
    $hidden = $this->actingAs($world->{$stranger})->call($method, $hiddenUri, $payload);

    expect($hidden->getStatusCode())->toBe($missing->getStatusCode())->toBe(404)
        ->and($hidden->getContent())->toBe($missing->getContent())
        ->and($hidden->getContent())->not->toContain(VisibilityWorld::TITLE)
        ->and(Thread::query()->withoutGlobalScopes()->count())->toBe($before);
})->with('stackRoutes')->with(['other', 'admin']);

it('serves the owner the stack of a private thread', function () {
    $world = VisibilityWorld::build();

    $this->actingAs($world->owner)->getJson("/threads/{$world->thread->public_id}/stack")->assertOk();
});

it('never lists a private stack in list_threads for another viewer', function () {
    $world = VisibilityWorld::build();
    $strangerRoot = Thread::factory()->create(['owner_user_id' => User::factory()->create()->id, 'name' => 'mine']);
    $this->actingAs($world->other);

    $result = app(StackToolRouter::class)->handle($strangerRoot, new YakToolCall('c1', 'list_threads', []));

    expect($result->isError)->toBeFalse()
        ->and($result->content)->toContain('mine')
        ->and($result->content)->not->toContain($world->child->name)
        ->and($result->content)->not->toContain(VisibilityWorld::TITLE);
});

it('does not message across stacks', function () {
    $world = VisibilityWorld::build();
    $strangerRoot = Thread::factory()->create(['name' => 'mine']);

    $result = app(StackToolRouter::class)->handle($strangerRoot, new YakToolCall('c1', 'send_message', ['to' => (string) $world->child->id, 'text' => 'hi']));

    expect($result->isError)->toBeTrue()
        ->and(StackThreadMessage::query()->count())->toBe(0);
});
