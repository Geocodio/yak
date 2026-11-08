<?php

namespace Tests\Support;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TurnState;
use App\Jobs\FinishFollowUpTurnJob;
use App\Jobs\FinishResearchFollowUpTurnJob;
use App\Jobs\FinishResearchTurnJob;
use App\Jobs\FinishRetryTurnJob;
use App\Jobs\FinishRunYakTurnJob;
use App\Jobs\FinishThreadTurnJob;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadStreams;
use App\YakPromptBuilder;
use Mockery;

/**
 * Runs a task job and the work after its turn in one call, answering the
 * turn from a FakeAgentRunner queue. The thread's broker is faked, so the
 * queued result stands in for what the runner would report.
 */
final class AgentJobHarness
{
    private const FINISHERS = [
        RunYakJob::class => FinishRunYakTurnJob::class,
        RetryYakJob::class => FinishRetryTurnJob::class,
        RunFollowUpJob::class => FinishFollowUpTurnJob::class,
        ResearchYakJob::class => FinishResearchTurnJob::class,
        ResearchFollowUpJob::class => FinishResearchFollowUpTurnJob::class,
    ];

    public static function run(object $job, FakeAgentRunner|AgentRunner $agent): void
    {
        self::fakeThreadInfrastructure();

        app()->call([$job, 'handle']);

        $run = TaskRun::query()->where('yak_task_id', $job->task->id)->latest('id')->first();

        if ($run === null || $run->turn_state === TurnState::Completed || ! $agent instanceof FakeAgentRunner) {
            return;
        }

        $request = new AgentRunRequest(
            prompt: (string) $run->prompt,
            systemPrompt: YakPromptBuilder::systemPrompt($job->task),
            containerName: (string) $run->thread?->sandbox_container,
            timeoutSeconds: 3570,
            maxTurns: (int) config('yak.max_turns'),
            model: (string) $run->model,
            resumeSessionId: $run->thread?->activeSession()?->session_id,
            task: $job->task,
            account: ClaudeAccount::query()->find($run->claude_account_id),
        );

        $finisher = self::finisherFor($job, $run);

        try {
            $result = $agent->run($request);
        } catch (\Throwable $e) {
            $run->forceFill(['turn_state' => TurnState::Failed])->save();
            $finisher->task = $job->task->refresh();
            (new \ReflectionMethod($finisher, 'reportFailure'))->invoke($finisher, $e->getMessage(), $e);

            return;
        }

        $run->forceFill([
            'turn_state' => $result->isError ? TurnState::Failed : TurnState::Completed,
            'last_result_event' => self::resultEvent($result),
            'assistant_messages' => $result->numTurns,
            'agent_ms' => $result->durationMs,
            'cost_usd' => $result->costUsd,
        ])->save();

        app()->call([$finisher, 'handle']);
    }

    private static function finisherFor(object $job, TaskRun $run): FinishThreadTurnJob
    {
        $class = self::FINISHERS[$job::class];

        return new $class($run->id);
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultEvent(AgentRunResult $result): array
    {
        return array_filter([
            'type' => 'result',
            'subtype' => $result->isError ? ($result->errorSubtype ?? 'error_during_execution') : 'success',
            'is_error' => $result->isError,
            'result' => $result->resultSummary . self::clarificationBlock($result) . self::wrongRepositoryBlock($result),
            'session_id' => $result->sessionId,
            'total_cost_usd' => $result->costUsd,
        ], fn ($value) => $value !== null);
    }

    private static function wrongRepositoryBlock(AgentRunResult $result): string
    {
        if (! $result->wrongRepository) {
            return '';
        }

        $verdict = json_encode(['reason' => $result->wrongRepositoryReason, 'suggested_repository' => $result->suggestedRepository]);

        return "\n\n```wrong_repository\n{$verdict}\n```";
    }

    private static function clarificationBlock(AgentRunResult $result): string
    {
        if ($result->clarificationQuestions === []) {
            return '';
        }

        $questions = array_map(fn ($question): array => $question->toArray(), $result->clarificationQuestions);

        return "\n\n```clarification\n" . json_encode(['questions' => $questions]) . "\n```";
    }

    private static function fakeThreadInfrastructure(): void
    {
        if (! app(ThreadStreams::class) instanceof FakeThreadStreams) {
            app()->instance(ThreadStreams::class, new FakeThreadStreams);
        }

        if (! app()->bound('agent-harness-thread-sandbox')) {
            $sandbox = Mockery::mock(ThreadSandbox::class);
            $sandbox->shouldReceive('ensureAwake')->andReturn('task-thread');
            $sandbox->shouldReceive('startingTurn')->andReturnUsing(fn ($thread, \Closure $startTurn) => $startTurn());
            $sandbox->shouldReceive('holdingSandbox')->andReturnUsing(fn ($thread, \Closure $work) => $work());
            $sandbox->shouldReceive('pause');
            app()->instance(ThreadSandbox::class, $sandbox);
            app()->instance('agent-harness-thread-sandbox', true);
        }

        if (! ClaudeAccount::query()->whereNull('owner_user_id')->where('is_default', true)->exists()) {
            ClaudeAccount::factory()->system()->create(['is_default' => true]);
        }
    }
}
