<?php

use App\Models\BranchDeployment;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('fills a public id on create and keeps it unique', function (string $modelClass) {
    $models = $modelClass::factory()->count(5)->create();

    expect($models->pluck('public_id')->unique())->toHaveCount(5);

    $model = new $modelClass;
    expect($model->getRouteKeyName())->toBe('public_id')
        ->and($models->first()->getRouteKey())->toBe($models->first()->public_id);
})->with([Thread::class, YakTask::class, BranchDeployment::class]);

it('generates a public id when the factory is bypassed', function () {
    $thread = Thread::factory()->make(['public_id' => null]);
    $thread->save();

    expect($thread->public_id)->toMatch('/^[0-9a-hjkmnp-tv-z]{8}$/');
});

it('retries when a generated id is already taken', function () {
    $taken = Thread::factory()->create();

    expect(Thread::generateUniquePublicId())->not->toBe($taken->public_id);
});

it('serves a thread page by public id', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('thread.id', $thread->public_id));
});

it('builds route urls from the public id', function () {
    $thread = Thread::factory()->create();

    expect(route('threads.show', $thread))->toEndWith("/threads/{$thread->public_id}");
});

it('redirects a numeric GET to the public id url with a 301', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->id}/diff?x=1")
        ->assertStatus(301)
        ->assertRedirect(url("/threads/{$thread->public_id}/diff") . '?x=1');

    $this->get("/threads/{$thread->id}")->assertStatus(301)->assertRedirect(route('threads.show', $thread));
});

it('redirects numeric task and deployment urls', function () {
    $task = YakTask::factory()->create();
    $deployment = BranchDeployment::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get("/tasks/{$task->id}")->assertStatus(301)->assertRedirect(route('tasks.show', $task));
    $this->get("/deployments/{$deployment->id}")->assertStatus(301)->assertRedirect(route('deployments.show', $deployment));
});

it('answers 404 for a numeric GET of a thread hidden from the viewer', function () {
    $hidden = Thread::factory()->private()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$hidden->id}")->assertNotFound();
    $this->get("/threads/{$hidden->public_id}")->assertNotFound();
});

it('answers 404 for a numeric GET of a thread that does not exist', function () {
    $this->actingAs(User::factory()->create())->get('/threads/999999')->assertNotFound();
});

it('never accepts a numeric id on a write', function () {
    $thread = Thread::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->post("/threads/{$thread->id}/stop")->assertNotFound();
    $this->post("/threads/{$thread->public_id}/stop")->assertStatus(302);
});

it('adds a unique public id to existing rows when the migration runs', function () {
    $threadIds = Thread::factory()->count(3)->create()->pluck('id');
    $taskIds = YakTask::factory()->count(2)->create()->pluck('id');
    $deploymentIds = BranchDeployment::factory()->count(2)->create()->pluck('id');

    Schema::table('threads', fn ($table) => $table->dropUnique(['public_id']));
    Schema::table('tasks', fn ($table) => $table->dropUnique(['public_id']));
    Schema::table('branch_deployments', fn ($table) => $table->dropUnique(['public_id']));
    foreach (['threads', 'tasks', 'branch_deployments'] as $table) {
        Schema::table($table, fn ($blueprint) => $blueprint->dropColumn('public_id'));
    }

    $parent = DB::table('threads')->where('id', $threadIds->first())->value('id');
    DB::table('thread_events')->insert([
        'thread_id' => $parent, 'sequence' => 1, 'type' => 'child', 'payload' => json_encode(['childThreadId' => $threadIds->last(), 'name' => 'scout']),
    ]);

    (require database_path('migrations/2026_11_22_010001_add_public_id_to_threads_tasks_and_branch_deployments.php'))->up();

    foreach (['threads' => $threadIds, 'tasks' => $taskIds, 'branch_deployments' => $deploymentIds] as $table => $ids) {
        $publicIds = DB::table($table)->whereIn('id', $ids)->pluck('public_id');

        expect($publicIds)->toHaveCount($ids->count())
            ->and($publicIds->unique())->toHaveCount($ids->count())
            ->and($publicIds->every(fn ($publicId) => preg_match('/^[0-9a-hjkmnp-tv-z]{8}$/', $publicId) === 1 && ! ctype_digit($publicId)))->toBeTrue();
    }

    $payload = json_decode(DB::table('thread_events')->where('type', 'child')->value('payload'), true);
    expect($payload['childThreadId'])->toBe(DB::table('threads')->where('id', $threadIds->last())->value('public_id'));
});

it('accepts the public id and the legacy numeric id on the CLI API', function () {
    [$device, $token] = makeDevice();
    $thread = Thread::factory()->create();

    $this->getJson("/api/cli/v1/threads/{$thread->public_id}", cliHeaders($token))
        ->assertOk()
        ->assertJsonPath('thread.public_id', $thread->public_id)
        ->assertJsonPath('thread.url', route('threads.show', $thread));

    $this->getJson("/api/cli/v1/threads/{$thread->id}", cliHeaders($token))->assertOk();
    $this->getJson('/api/cli/v1/threads/zzzzzzzz', cliHeaders($token))->assertNotFound();
});
