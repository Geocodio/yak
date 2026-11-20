<?php

use App\Models\Artifact;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadWalkthrough;
use App\Models\User;
use App\Models\YakTask;
use App\Support\AppEmbedRoutes;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A thread owned by the phone's user with an open question, as the embed page shows it.
 *
 * @return array{0: Thread, 1: array<string, string>, 2: User}
 */
function embedQuestionThread(): array
{
    Queue::fake();
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'title' => 'Add a retry to the import job']);
    YakTask::factory()->withClarificationQuestions([
        ['id' => 'scope', 'header' => 'Scope', 'question' => 'Which scope?', 'options' => [['label' => 'Small', 'description' => 'Only the import job'], ['label' => 'Large', 'description' => 'Every job']], 'multi_select' => false],
    ])->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);

    return [$thread, $headers, $user];
}

it('shows the conversation and cards without header, rail or composer', function () {
    [$thread, $headers] = embedQuestionThread();

    visitEmbedThread($thread, $headers)
        ->assertPresent('[data-testid="thread-conversation"]')
        ->assertPresent('[data-testid="questions-form"]')
        ->assertMissing('[data-testid="thread-header"]')
        ->assertMissing('[data-testid="thread-composer"]')
        ->assertMissing('[data-testid="thread-phone-tabs"]')
        ->assertMissing('[data-testid="thread-dock"]')
        ->assertMissing('[data-testid="thread-sidebar"]')
        ->assertMissing('[data-testid="app-shell"]')
        ->assertMissing('[data-testid="mobile-nav-toggle"]')
        ->assertNoJavaScriptErrors();
});

it('keeps cards inline on a wide screen', function () {
    [$thread, $headers] = embedQuestionThread();

    visitEmbedThread($thread, $headers, 1280, 900)
        ->assertPresent('[data-testid="thread-conversation-scroller"] [data-testid="questions-form"]')
        ->assertMissing('[data-testid="thread-sidebar"]')
        ->assertMissing('[data-testid="thread-composer"]')
        ->assertNoJavaScriptErrors();
});

it('shows shared files and a walkthrough in the embed page', function () {
    Storage::fake('artifacts');
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'branch' => 'yak/retry']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => 'yak/retry']);
    Artifact::factory()->shared('research-report.html')->create(['yak_task_id' => $task->id, 'size_bytes' => 212_000]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [['type' => 'text', 'text' => 'The retry is in.', 'html' => '<p>The retry is in.</p>']]]]);
    ThreadWalkthrough::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'number' => 1, 'anchor_sequence' => 1]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'video_cut', 'role' => 'cut', 'filename' => 'walkthrough.mp4', 'disk_path' => "{$task->id}/walkthrough.mp4", 'size_bytes' => 1]);

    visitEmbedThread($thread, $headers)
        ->assertPresent('[data-testid="thread-shared-files"]')
        ->assertPresent('[data-testid="thread-walkthrough"]')
        ->assertMissing('[data-testid="thread-composer"]')
        ->assertNoJavaScriptErrors();
});

it('a normal session still shows the full page', function () {
    [$thread, , $user] = embedQuestionThread();
    $this->actingAs($user);

    visit("/threads/{$thread->public_id}")->resize(1280, 900)
        ->assertPresent('[data-testid="app-shell"]')
        ->assertPresent('[data-testid="thread-header"]')
        ->assertPresent('[data-testid="thread-composer"]')
        ->assertPresent('[data-testid="thread-sidebar"]')
        ->assertMissing('[data-testid="embed-shell"]')
        ->assertNoJavaScriptErrors();
});

it('only calls allow-listed routes on load and while answering a card', function () {
    [$thread, $headers] = embedQuestionThread();
    $recorded = recordEmbedRequests();

    visitEmbedThread($thread, $headers)
        ->wait(2)
        ->click('[data-testid="question-scope"] >> text=Small')
        ->click('[data-testid="questions-submit"]')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    $handOff = ['login', 'app.web-session.store', 'app.embed.session'];
    $embedRequests = collect($recorded())->reject(fn (array $request) => in_array($request['route'], $handOff, true));

    expect($embedRequests->pluck('route')->all())->toContain('app.embed.threads.show', 'threads.clarification-answers.store');

    $embedRequests->each(function (array $request) {
        expect(in_array($request['route'], AppEmbedRoutes::ALLOWED, true) || $request['route'] === AppEmbedRoutes::BROADCAST_AUTH_URI)
            ->toBeTrue("{$request['route']} is not on the embed allow-list")
            ->and($request['status'])->not->toBe(404, "{$request['route']} answered 404");
    });
});
