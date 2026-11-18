<?php

use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('redirects a work task to its thread for good', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->get(route('tasks.show', $task))->assertStatus(301)->assertRedirect(route('threads.show', $thread));
});

it('lands a numeric task id on the thread', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->get('/tasks/' . $task->id)->assertStatus(301)->assertRedirect(route('threads.show', $thread));
});

it('redirects a follow-up task to its message in the thread', function () {
    $thread = Thread::factory()->create();
    $root = YakTask::factory()->create(['thread_id' => $thread->id]);
    $followUp = YakTask::factory()->create(['thread_id' => $thread->id, 'parent_task_id' => $root->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $followUp->id, 'thread_id' => $thread->id]);
    $message = ThreadTurnMessage::factory()->create(['task_run_id' => $run->id]);

    $this->get(route('tasks.show', $followUp))->assertStatus(301)->assertRedirect(route('threads.show', $thread) . '#message-' . $message->uuid);
});

it('redirects a moved task to the thread it moved to', function () {
    Thread::factory()->closed()->create(['close_reason' => 'moved']);
    $new = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $new->id]);

    $this->get(route('tasks.show', $task))->assertStatus(301)->assertRedirect(route('threads.show', $new));
});

it('redirects a task of a legacy conversation that continued to its current thread', function () {
    $root = YakTask::factory()->create(['thread_id' => null]);
    $legacy = Thread::factory()->closed()->create(['is_legacy' => true, 'legacy_root_task_id' => $root->id]);
    $continuation = Thread::factory()->create(['continued_from_thread_id' => $legacy->id]);
    $child = YakTask::factory()->create(['thread_id' => $continuation->id, 'parent_task_id' => $root->id]);

    $this->get(route('tasks.show', $child) . '?log=9')->assertStatus(301)->assertRedirect(route('threads.show', $continuation));
});

it('redirects a task of a legacy conversation that did not continue to the legacy thread with its log', function () {
    $root = YakTask::factory()->create(['thread_id' => null]);
    $legacy = Thread::factory()->closed()->create(['is_legacy' => true, 'legacy_root_task_id' => $root->id]);
    $root->forceFill(['thread_id' => $legacy->id])->save();

    $this->get(route('tasks.show', $root) . '?log=9')->assertStatus(301)->assertRedirect(route('threads.show', $legacy) . '?log=9');
});

it('carries the walkthrough time and the repository to the thread and drops the rest', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->get(route('tasks.show', $task) . '?t=95&repo=acme%2Fwidgets&tab=activity&run=3&attempt=2&before=4&after=5&log=8')
        ->assertStatus(301)
        ->assertRedirect(route('threads.show', $thread) . '?walkthrough=95&repo=acme%2Fwidgets');
});

it('redirects a review to its pull request page at its run', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => 42]);

    $this->get(route('tasks.show', $task) . '?log=3&t=4')->assertStatus(301)
        ->assertRedirect(route('pr-reviews.for-pr', ['acme/widgets', 42]) . '#run-' . $task->public_id);
});

it('answers 404 for a review without a number, whatever url it stores', function () {
    $linked = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => null, 'pr_url' => 'https://example.test/change/abc']);
    $bare = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => null, 'pr_url' => null]);

    $this->get(route('tasks.show', $linked))->assertNotFound();
    $this->get(route('tasks.show', $bare))->assertNotFound();
});

it('redirects a setup task and a repository research task to the Runs tab', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/widgets']);
    $setup = YakTask::factory()->create(['mode' => TaskMode::Setup, 'repo' => 'acme/widgets']);
    $research = YakTask::factory()->create(['mode' => TaskMode::Research, 'repo' => 'acme/widgets', 'external_id' => 'risk-profile-acme']);

    $this->get(route('tasks.show', $setup))->assertStatus(301)->assertRedirect(route('repos.runs.show', [$repository, $setup]));
    $this->get(route('tasks.show', $research))->assertStatus(301)->assertRedirect(route('repos.runs.show', [$repository, $research]));
});

it('answers 404 for a setup task whose repository is gone', function () {
    $setup = YakTask::factory()->create(['mode' => TaskMode::Setup, 'repo' => 'gone/repo']);

    $this->get(route('tasks.show', $setup))->assertNotFound();
});

it('answers 404 for a work task whose thread was deleted and creates no thread', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $thread->delete();

    $this->get(route('tasks.show', $task))->assertNotFound();
    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('answers 404 for an unknown task', function () {
    $this->get('/tasks/zzzzzzzz')->assertNotFound();
    $this->get('/tasks/987654')->assertNotFound();
});

it('answers 404 without a Location for a task on another person\'s private thread, by public or numeric id', function () {
    $thread = Thread::factory()->private()->create(['title' => 'Quarterly layoff plan']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    foreach ([$task->public_id, $task->id] as $key) {
        $response = $this->get('/tasks/' . $key);

        $response->assertNotFound();
        expect($response->headers->has('Location'))->toBeFalse()
            ->and($response->getContent())->not->toContain('Quarterly layoff plan');
    }
});

it('redirects the owner of a private thread to it', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->actingAs($owner)->get(route('tasks.show', $task))->assertStatus(301)->assertRedirect(route('threads.show', $thread));
    $this->get('/tasks/' . $task->id)->assertStatus(301)->assertRedirect(route('threads.show', $thread));
});

it('redirects a person a private thread is shared with', function () {
    $guest = User::factory()->create();
    $thread = Thread::factory()->private()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->actingAs($guest)->get(route('tasks.show', $task))->assertStatus(301)->assertRedirect(route('threads.show', $thread));
});

it('redirects any signed-in user to a team thread', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->actingAs(User::factory()->create())->get(route('tasks.show', $task))->assertStatus(301)->assertRedirect(route('threads.show', $thread));
});

it('sends a guest to the login page', function () {
    auth()->logout();
    $task = YakTask::factory()->create(['thread_id' => Thread::factory()->create()->id]);

    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
});

it('redirects the task log to the thread log', function () {
    $this->get(route('tasks'))->assertStatus(301)->assertRedirect(route('threads.log'));
    $this->get(route('tasks', ['repo' => 'acme/widgets', 'status' => 'failed', 'page' => 2]))
        ->assertStatus(301)->assertRedirect(route('threads.log', ['repo' => 'acme/widgets']));
});

it('redirects the reviews tab to the pull request reviews', function () {
    $this->get(route('tasks', ['tab' => 'reviews']))->assertStatus(301)->assertRedirect(route('pr-reviews'));
});

it('redirects the setup tab to the setup runs of its repository, or to the repositories', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/widgets']);

    $this->get(route('tasks', ['tab' => 'setup', 'repo' => 'acme/widgets']))
        ->assertStatus(301)->assertRedirect(route('repos.runs.index', ['repository' => $repository, 'kind' => 'setup']));
    $this->get(route('tasks', ['tab' => 'setup']))->assertStatus(301)->assertRedirect(route('repos'));
    $this->get(route('tasks', ['tab' => 'setup', 'repo' => 'gone/repo']))->assertStatus(301)->assertRedirect(route('repos'));
});

it('sends a guest from the task log to the login page', function () {
    auth()->logout();

    $this->get(route('tasks'))->assertRedirect(route('login'));
});

it('gives a waiting old conversation its thread when its link is opened, and reuses it', function () {
    $this->withoutVite();
    $root = YakTask::factory()->success()->create(['thread_id' => null, 'repo' => 'acme/widgets']);
    $followUp = YakTask::factory()->awaitingClarification()->withClarificationQuestions()->create(['thread_id' => null, 'parent_task_id' => $root->id, 'repo' => 'acme/widgets']);

    $response = $this->get(route('tasks.show', $followUp))->assertStatus(301);

    $thread = Thread::query()->withoutGlobalScopes()->sole();
    expect($root->fresh()->thread_id)->toBe($thread->id)->and($followUp->fresh()->thread_id)->toBe($thread->id);
    $response->assertRedirect(route('threads.show', $thread));

    $this->get(route('tasks.show', $root))->assertStatus(301)->assertRedirect(route('threads.show', $thread));
    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(1);

    $this->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('question.taskId', $followUp->public_id)->has('question.questions'));
});

it('creates no thread for a review or a setup task', function () {
    Repository::factory()->create(['slug' => 'acme/widgets']);
    $review = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => 42, 'thread_id' => null]);
    $setup = YakTask::factory()->create(['mode' => TaskMode::Setup, 'repo' => 'acme/widgets', 'thread_id' => null]);

    $this->get(route('tasks.show', $review))->assertStatus(301);
    $this->get(route('tasks.show', $setup))->assertStatus(301);

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('creates no thread for a task the viewer cannot see', function () {
    $thread = Thread::factory()->private()->create();
    $task = YakTask::factory()->awaitingClarification()->create(['thread_id' => $thread->id]);

    $this->get(route('tasks.show', $task))->assertNotFound();

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(1);
});
