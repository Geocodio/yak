<?php

use App\Enums\RepositoryRole;
use App\Enums\StackThreadMessageKind;
use App\Jobs\StartChildThreadJob;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->root = Thread::factory()->create(['title' => 'Root work', 'repo' => 'acme/widgets', 'branch' => 'yak/root']);
    ThreadRepository::factory()->create(['thread_id' => $this->root->id, 'repo' => 'acme/widgets', 'role' => RepositoryRole::Primary]);
    $this->child = Thread::factory()->child($this->root)->create(['name' => 'builder', 'title' => 'Build it', 'model' => 'sonnet']);
});

it('returns the stack for a root and for a child', function () {
    StackThreadMessage::factory()->create([
        'root_thread_id' => $this->root->id,
        'from_thread_id' => $this->child->id,
        'to_thread_id' => $this->root->id,
        'kind' => StackThreadMessageKind::Result,
        'text' => str_repeat('x', 600),
    ]);

    $fromRoot = $this->actingAs($this->user)->getJson(route('threads.stack.show', $this->root))->assertOk();
    $fromRoot->assertJsonPath('rootId', $this->root->public_id)
        ->assertJsonPath('isRoot', true)
        ->assertJsonPath('parent', null)
        ->assertJsonPath('children.0.id', $this->child->public_id)
        ->assertJsonPath('children.0.name', 'builder')
        ->assertJsonPath('children.0.state', 'queued')
        ->assertJsonPath('limit.current', 3)
        ->assertJsonPath('canDrive', true)
        ->assertJsonPath('canStop', true);
    expect(mb_strlen($fromRoot->json('messages.0.text')))->toBeLessThanOrEqual(500)
        ->and($fromRoot->json('messages.0.fromName'))->toBe('builder')
        ->and($fromRoot->json('usage.note'))->toContain('Subscription');

    $this->actingAs($this->user)->getJson(route('threads.stack.show', $this->child))->assertOk()
        ->assertJsonPath('rootId', $this->root->public_id)
        ->assertJsonPath('isRoot', false)
        ->assertJsonPath('parent.id', $this->root->public_id)
        ->assertJsonPath('parent.title', 'Root work');
});

it('hides a private stack from a second user with the missing-thread 404', function () {
    $owner = User::factory()->create();
    $private = Thread::factory()->private($owner)->create();
    $privateChild = Thread::factory()->child($private)->create();

    $this->actingAs($this->user)->getJson(route('threads.stack.show', $private))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('threads.stack.show', $privateChild))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('threads.stack.children.diff', [$private, $privateChild]))->assertNotFound();
    $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $private), ['name' => 'a', 'brief' => 'b', 'kind' => 'general', 'model' => 'sonnet'])->assertNotFound();

    $this->actingAs($owner)->getJson(route('threads.stack.show', $private))->assertOk()->assertJsonPath('children.0.id', $privateChild->public_id);
});

it('shows a shared stack read-only', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $private = Thread::factory()->private($owner)->create();
    Thread::factory()->child($private)->create();
    ThreadShare::query()->create(['thread_id' => $private->id, 'user_id' => $guest->id]);

    $this->actingAs($guest)->getJson(route('threads.stack.show', $private))->assertOk()
        ->assertJsonCount(1, 'children')
        ->assertJsonPath('canDrive', false)
        ->assertJsonPath('canStop', false);
});

it('adds a child from the thread page', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $this->root), [
        'name' => 'Docs Scout',
        'brief' => 'Find the docs.',
        'kind' => 'research',
        'model' => 'haiku',
    ])->assertCreated();

    $child = Thread::query()->findOrFail($response->json('id'));
    expect($child->parent_thread_id)->toBe($this->root->id)
        ->and($child->name)->toBe('docs-scout')
        ->and($child->model)->toBe('haiku');
    Queue::assertPushed(StartChildThreadJob::class, fn (StartChildThreadJob $job) => $job->childThreadId === $child->id);
});

it('refuses a child from a child, a device-owned parent and an invalid kind', function () {
    Queue::fake();
    $payload = ['name' => 'a', 'brief' => 'b', 'kind' => 'general', 'model' => 'sonnet'];

    $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $this->child), $payload)->assertUnprocessable();
    $this->actingAs($this->user)->postJson(route('threads.stack.children.store', Thread::factory()->ownedByDevice()->create()), $payload)->assertUnprocessable();
    $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $this->root), [...$payload, 'kind' => 'root'])->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('serves a child diff only inside the stack', function () {
    ThreadDiff::factory()->create(['thread_id' => $this->child->id]);
    $stranger = Thread::factory()->child(Thread::factory()->create())->create();

    $this->actingAs($this->user)->getJson(route('threads.stack.children.diff', [$this->root, $this->child]))->assertOk()
        ->assertJsonStructure(['diff' => ['headSha', 'files']]);
    $this->actingAs($this->user)->getJson(route('threads.stack.children.diff', [$this->root, $stranger]))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('threads.stack.children.diff', [$this->root, $this->root]))->assertNotFound();
});

it('rejects an unknown model on add sub-thread', function () {
    Queue::fake();
    $payload = ['name' => 'a', 'brief' => 'b', 'kind' => 'general'];

    $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $this->root), [...$payload, 'model' => 'gpt-9'])
        ->assertUnprocessable()->assertJsonValidationErrors('model');
    expect(Thread::query()->where('parent_thread_id', $this->root->id)->count())->toBe(1);

    $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $this->root), [...$payload, 'model' => 'opus'])->assertCreated();
});

it('records the requesting user as the child creator and driver', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $team = Thread::factory()->create(['repo' => 'acme/widgets', 'branch' => 'yak/t', 'owner_user_id' => $owner->id]);
    ThreadRepository::factory()->create(['thread_id' => $team->id, 'repo' => 'acme/widgets', 'role' => RepositoryRole::Primary]);

    $id = $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $team), ['name' => 'a', 'brief' => 'b', 'kind' => 'general', 'model' => 'sonnet'])
        ->assertCreated()->json('id');

    $child = Thread::query()->findOrFail($id);
    expect($child->created_by_user_id)->toBe($this->user->id)
        ->and($child->tasks()->sole()->started_by_user_id)->toBe($this->user->id);
});

it('refuses a viewer who cannot drive the parent', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $private = Thread::factory()->private($owner)->create();
    ThreadShare::query()->create(['thread_id' => $private->id, 'user_id' => $guest->id]);

    $this->actingAs($guest)->postJson(route('threads.stack.children.store', $private), ['name' => 'a', 'brief' => 'b', 'kind' => 'general', 'model' => 'sonnet'])->assertForbidden();

    expect(Thread::query()->withoutGlobalScopes()->where('parent_thread_id', $private->id)->exists())->toBeFalse();
});

it('answers 404 and creates nothing for a user who cannot view the parent', function () {
    $private = Thread::factory()->private(User::factory()->create())->create();

    $this->actingAs($this->user)->postJson(route('threads.stack.children.store', $private), ['name' => 'a', 'brief' => 'b', 'kind' => 'general', 'model' => 'sonnet'])->assertNotFound();

    expect(Thread::query()->withoutGlobalScopes()->where('parent_thread_id', $private->id)->exists())->toBeFalse();
});
