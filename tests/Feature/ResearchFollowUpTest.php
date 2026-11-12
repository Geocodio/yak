<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Jobs\AfterTurn\FinishResearchFollowUpTurnJob;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\RunFollowUpJob;
use App\Models\Artifact;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\FollowUpTaskFactory;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../Support/FinishTurnHelpers.php';

function finishedResearchTask(array $overrides = []): YakTask
{
    return YakTask::factory()->success()->create($overrides + [
        'mode' => TaskMode::Research,
        'source' => 'dashboard',
        'repo' => 'research-repo',
        'session_id' => 'sess_research',
        'result_summary' => 'Three retry strategies fit the client.',
        'pr_url' => null,
    ]);
}

/*
|--------------------------------------------------------------------------
| Eligibility and routing
|--------------------------------------------------------------------------
*/

test('a finished research task accepts a follow-up and a failed one does not', function () {
    expect(finishedResearchTask()->acceptsFollowUp())->toBeTrue();
    expect(finishedResearchTask(['status' => TaskStatus::Failed])->acceptsFollowUp())->toBeFalse();
    expect(YakTask::factory()->success()->create(['pr_url' => null])->acceptsFollowUp())->toBeFalse();
});

test('the factory creates a read-only research child and dispatches ResearchFollowUpJob', function () {
    Queue::fake();

    $head = finishedResearchTask([
        'branch_name' => null,
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.2',
        'external_id' => 'SLACK-1',
    ]);

    $child = app(FollowUpTaskFactory::class)->create($head, 'Which is cheapest?', 'slack');

    expect($child)->not->toBeNull()
        ->and($child->parent_task_id)->toBe($head->id)
        ->and($child->mode)->toBe(TaskMode::Research)
        ->and($child->session_id)->toBe('sess_research')
        ->and($child->slack_channel)->toBe('C1')
        ->and($child->pr_url)->toBeNull()
        ->and($child->branch_name)->toBeNull();

    Queue::assertPushed(ResearchFollowUpJob::class, fn (ResearchFollowUpJob $job) => $job->task->id === $child->id);
    Queue::assertNotPushed(RunFollowUpJob::class);
    expect($child->fresh()->dispatched_at)->not->toBeNull();
});

test('the factory still declines a closed-PR task that is not research', function () {
    Queue::fake();
    $task = YakTask::factory()->merged()->create(['branch_name' => 'yak/M-1']);

    expect(app(FollowUpTaskFactory::class)->create($task, 'too late', 'dashboard'))->toBeNull();
    Queue::assertNothingPushed();
});

test('the dispatcher accepts ResearchFollowUpJob as a claimable job', function () {
    expect(AgentJobDispatcher::claimableJobClasses())->toContain(ResearchFollowUpJob::class);
});

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/

test('the composer offers a research follow-up question on a finished research task', function () {
    $this->actingAs(User::factory()->create());
    $task = finishedResearchTask();

    $this->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('composer.state', 'follow_up')
            ->where('composer.placeholder', 'Ask Yak a follow-up question about this research…'));
});

test('the task message controller starts a research follow-up', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $task = finishedResearchTask();

    $this->post(route('tasks.messages.store', $task), ['message' => 'Which is cheapest?'])
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('success', 'Sent to Yak. It will answer in this conversation.');

    expect(YakTask::where('parent_task_id', $task->id)->count())->toBe(1);
    Queue::assertPushed(ResearchFollowUpJob::class);
});

test('the research artifact link points at the newest report in the conversation', function () {
    $this->actingAs(User::factory()->create());
    $head = finishedResearchTask();
    Artifact::create(['yak_task_id' => $head->id, 'type' => 'research', 'role' => null, 'filename' => 'research.html', 'disk_path' => "{$head->id}/research.html", 'size_bytes' => 1]);
    $child = finishedResearchTask(['parent_task_id' => $head->id]);
    Artifact::create(['yak_task_id' => $child->id, 'type' => 'research', 'role' => null, 'filename' => 'research.html', 'disk_path' => "{$child->id}/research.html", 'size_bytes' => 1]);

    $this->get(route('tasks.show', $head))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.researchArtifactUrl', route('artifacts.viewer', ['task' => $child->id, 'filename' => 'research.html'])));
});

/*
|--------------------------------------------------------------------------
| The job
|--------------------------------------------------------------------------
*/

/**
 * Writes a research follow-up turn for the child and finishes it with the given answer.
 *
 * @param  array{task: YakTask, thread: Thread, shell: FakeSandboxManager, sent: array<int, array<string, string>>}  $context
 */
function runResearchFollowUp(array $context, string $answer = 'Backoff is cheapest.', bool $isError = false): void
{
    (new ResearchFollowUpJob($context['task']->refresh()))->handle();

    $run = TaskRun::query()->where('yak_task_id', $context['task']->id)->sole();
    $run->forceFill([
        'turn_state' => $isError ? TurnState::Failed : TurnState::Completed,
        'last_result_event' => resultEvent($answer, $isError ? ['is_error' => true, 'subtype' => 'error_during_execution'] : []),
        'assistant_turns' => 1,
    ])->save();
    runFinisher($run, FinishResearchFollowUpTurnJob::class);
}

/**
 * @return array{task: YakTask, thread: Thread, shell: FakeSandboxManager, sent: array<int, array<string, string>>}
 */
function researchFollowUpFixture(?FakeSandboxManager $shell = null): array
{
    Queue::fake();
    Storage::fake('artifacts');
    $context = pendingRunYak(['mode' => TaskMode::Research, 'source' => 'dashboard', 'description' => 'Which is cheapest?'], $shell);

    $head = finishedResearchTask(['repo' => 'test-repo', 'thread_id' => $context['thread']->id]);
    Storage::disk('artifacts')->put("{$head->id}/research.html", '<html>first report</html>');
    Artifact::create(['yak_task_id' => $head->id, 'type' => 'research', 'role' => null, 'filename' => 'research.html', 'disk_path' => "{$head->id}/research.html", 'size_bytes' => 10]);
    $context['task']->update(['parent_task_id' => $head->id]);

    return $context;
}

test('a research follow-up resumes the thread and carries the previous answer and report', function () {
    $context = researchFollowUpFixture();

    runResearchFollowUp($context);

    $child = $context['task']->fresh();
    expect($context['sent'][1]['text'])->toContain('Which is cheapest?')
        ->toContain('Three retry strategies fit the client.')
        ->toContain('.yak-artifacts/previous-research.html')
        ->and($context['shell']->commandsMatching('.yak-artifacts'))->not->toBe([])
        ->and($child->status)->toBe(TaskStatus::Success)
        ->and($child->result_summary)->toBe('Backoff is cheapest.')
        ->and($child->started_at)->not->toBeNull()
        ->and($child->completed_at)->not->toBeNull()
        ->and(Artifact::where('yak_task_id', $child->id)->count())->toBe(0);
});

test('a research follow-up that writes a revised report stores it as a new research artifact', function () {
    $shell = new class extends FakeSandboxManager
    {
        public function fileExists(string $containerName, string $path): bool
        {
            return str_ends_with($path, '/research.html');
        }

        public function pullFile(string $containerName, string $remotePath, string $localPath): void
        {
            if (! is_dir(dirname($localPath))) {
                mkdir(dirname($localPath), 0755, true);
            }
            file_put_contents($localPath, '<html>revised</html>');
        }
    };
    $context = researchFollowUpFixture($shell);

    runResearchFollowUp($context);

    $child = $context['task']->fresh();
    $artifact = Artifact::where('yak_task_id', $child->id)->first();
    expect($artifact)->not->toBeNull()
        ->and($artifact->type)->toBe('research')
        ->and($artifact->disk_path)->toBe("{$child->id}/research.html")
        ->and($child->latestResearchArtifact()->id)->toBe($artifact->id);
});

test('a research follow-up fails the task when the agent errors', function () {
    $context = researchFollowUpFixture();

    runResearchFollowUp($context, 'boom', isError: true);

    expect($context['task']->fresh()->status)->toBe(TaskStatus::Failed);
});

test('a research follow-up that asks parks the child task', function () {
    $context = researchFollowUpFixture();
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('scope')->toArray()]]) . "\n```";

    runResearchFollowUp($context, "Need a call\n\n{$block}");

    expect($context['task']->fresh()->status)->toBe(TaskStatus::AwaitingClarification)
        ->and(Artifact::where('yak_task_id', $context['task']->id)->count())->toBe(0);
});

test('a research follow-up resumed with answers sends the answers prompt', function () {
    $context = researchFollowUpFixture();
    $child = $context['task'];
    $child->update(['clarification_rounds' => YakTask::factory()->withClarificationQuestions()->make()->clarification_rounds]);
    $child->recordClarificationAnswers(['scope' => ['choices' => ['Small'], 'other' => null]], null, 'Michele');

    (new ResearchFollowUpJob($child->fresh()))->handle();

    expect($context['sent'][1]['text'])->toContain("Q: Which scope?\nA: Small");
});
