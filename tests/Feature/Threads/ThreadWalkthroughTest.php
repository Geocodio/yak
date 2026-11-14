<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TurnState;
use App\Jobs\CreatePullRequestJob;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\PullRequestBodySections;
use App\Services\PullRequestBodyUpdater;
use App\Services\Threads\ThreadArtifacts;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    $this->thread = Thread::factory()->create(['branch' => 'yak/census']);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id]);
    $this->user = User::factory()->create();
});

it('sends the walkthrough prompt to Claude and the short request to the timeline', function (string $intent) {
    $turns = $this->mock(ThreadTurns::class);
    $turns->shouldReceive('start')->once()->withArgs(fn ($task, string $prompt, $user, $model, $kind = null, $shownText = null, $sentIntent = null) => $shownText === 'Record a walkthrough'
        && $sentIntent === $intent
        && str_contains($prompt, 'VISUAL CAPTURE')
        && str_contains($prompt, '.yak-artifacts'));

    $this->actingAs($this->user)
        ->post(route('threads.messages.store', $this->thread), ['message' => 'Record a walkthrough', 'intent' => $intent])
        ->assertSessionHas('success');
})->with(['record' => 'record_walkthrough', 'record and open' => 'record_walkthrough_and_open_pr']);

it('refuses a walkthrough request while a turn runs', function () {
    TaskRun::factory()->create(['yak_task_id' => $this->task->id, 'thread_id' => $this->thread->id, 'turn_state' => TurnState::Running]);
    $this->mock(ThreadTurns::class)->shouldNotReceive('start', 'steer');

    $this->actingAs($this->user)
        ->post(route('threads.messages.store', $this->thread), ['message' => 'x', 'intent' => 'record_walkthrough'])
        ->assertSessionHas('error');
});

it('asks a private thread to acknowledge before recording and opening the PR', function () {
    $thread = Thread::factory()->private($this->user)->create();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $turns = $this->mock(ThreadTurns::class);
    $turns->shouldReceive('start')->once();

    $this->actingAs($this->user)
        ->postJson(route('threads.messages.store', $thread), ['message' => 'x', 'intent' => 'record_walkthrough_and_open_pr'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('acknowledged_private_publish');

    $this->actingAs($this->user)
        ->postJson(route('threads.messages.store', $thread), ['message' => 'x', 'intent' => 'record_walkthrough_and_open_pr', 'acknowledged_private_publish' => true])
        ->assertOk();
});

function requestedRun(Thread $thread, YakTask $task, string $intent): TaskRun
{
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);
    ThreadTurnMessage::query()->create(['task_run_id' => $run->id, 'uuid' => (string) Str::uuid(), 'kind' => 'prompt', 'state' => 'sent', 'text' => 'Record', 'intent' => $intent]);

    return $run;
}

it('opens the pull request when a record-and-open turn has been collected', function () {
    $run = requestedRun($this->thread, $this->task, 'record_walkthrough_and_open_pr');

    app(ThreadArtifacts::class)->openPullRequestIfRequested($this->thread, $run);

    Queue::assertPushed(CreatePullRequestJob::class, fn (CreatePullRequestJob $job) => $job->task->is($this->task) && ! $job->refreshOnly);
    expect($this->task->fresh()->branch_name)->toBe('yak/census');
});

it('does not open a pull request for a plain recording or when one is open', function () {
    app(ThreadArtifacts::class)->openPullRequestIfRequested($this->thread, requestedRun($this->thread, $this->task, 'record_walkthrough'));

    $this->task->update(['pr_url' => 'https://github.com/acme/web/pull/9']);
    app(ThreadArtifacts::class)->openPullRequestIfRequested($this->thread, requestedRun($this->thread, $this->task, 'record_walkthrough_and_open_pr'));

    Queue::assertNothingPushed();
});

it('gives the thread page its recording and artifacts', function () {
    $this->task->update(['pr_url' => 'https://github.com/acme/web/pull/9']);
    Artifact::create(['yak_task_id' => $this->task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'form.png', 'disk_path' => "{$this->task->id}/form.png", 'size_bytes' => 1, 'caption' => 'The form']);

    $this->actingAs($this->user)->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('walkthrough.isRecording', false)
        ->where('walkthrough.isInPullRequest', true)
        ->where('walkthrough.video', null)
        ->where('walkthrough.screenshots.0.caption', 'The form'));
});

it('marks the page as recording while a walkthrough turn runs', function () {
    $run = requestedRun($this->thread, $this->task, 'record_walkthrough');
    $run->update(['turn_state' => TurnState::Running]);

    $this->actingAs($this->user)->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('walkthrough.isRecording', true));
});

it('gives a thread without a recording a null walkthrough', function () {
    $this->actingAs($this->user)->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page->where('walkthrough', null));
});

it('refreshes only the screenshots of an open pull request', function () {
    config()->set('yak.channels.github.installation_id', 77);
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web', 'default_branch' => 'main']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/web', 'branch_name' => 'yak/census', 'pr_url' => 'https://github.com/acme/web/pull/9', 'pr_number' => 9,
        'pr_body_update' => 'Stale description', 'result_summary' => 'Summary',
    ]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'form.png', 'disk_path' => "{$task->id}/form.png", 'size_bytes' => 1, 'caption' => 'The form']);

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(['number' => 9, 'html_url' => 'https://github.com/acme/web/pull/9']);
    $github->shouldNotReceive('commentOnPullRequest', 'createPullRequest');
    $this->mock(PullRequestBodyUpdater::class)->shouldReceive('setSections')->once()
        ->withArgs(fn (string $repo, int $number, array $sections) => array_keys($sections) === [PullRequestBodySections::SCREENSHOTS])
        ->andReturn([PullRequestBodySections::SCREENSHOTS]);

    (new CreatePullRequestJob($task, refreshOnly: true))->handle($github);
});

it('never opens a pull request from a refresh when none is open', function () {
    config()->set('yak.channels.github.installation_id', 77);
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web', 'default_branch' => 'main']);
    $task = YakTask::factory()->create(['repo' => 'acme/web', 'branch_name' => 'yak/census']);
    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(null);
    $github->shouldNotReceive('createPullRequest');

    (new CreatePullRequestJob($task, refreshOnly: true))->handle($github);
});
