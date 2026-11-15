<?php

namespace Tests\Support;

use App\Enums\ClaudeAccountKind;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;

/**
 * The neutral stack the stack fixtures record: a private root with three
 * sub-threads in three states and seven runs. Build it after the test fixed
 * the clock at 2026-11-15 12:00:00.
 */
final class StackWorld
{
    public function __construct(
        public readonly User $owner,
        public readonly User $recipient,
        public readonly Thread $root,
        public readonly Thread $working,
        public readonly Thread $failed,
        public readonly Thread $research,
    ) {}

    public static function build(): self
    {
        $owner = User::factory()->create(['name' => 'Test person']);
        $recipient = User::factory()->create(['name' => 'Second person']);
        $account = ClaudeAccount::factory()->create(['name' => 'Team account', 'kind' => ClaudeAccountKind::SetupToken, 'owner_user_id' => $owner->id]);

        $root = Thread::factory()->private($owner)->create([
            'title' => 'Move search to the new index',
            'name' => 'move-search',
            'repo' => 'example/api',
            'branch' => 'yak/move-search',
            'created_by_user_id' => $owner->id,
            'claude_account_id' => $account->id,
            'child_awake_limit' => 2,
        ]);
        app(ThreadSharing::class)->apply($root, $owner, ThreadShareMode::People, [$recipient->id]);

        $working = Thread::factory()->child($root)->create([
            'title' => 'Backfill the new index',
            'name' => 'backfill-job',
            'model' => 'sonnet',
            'branch' => 'yak/move-search/backfill-job',
            'current_step' => 'Writing the migration',
            'sandbox_state' => ThreadSandboxState::Running,
            'sandbox_container' => 'example-container-1',
            'created_by_user_id' => $owner->id,
        ]);
        ThreadDiff::factory()->create(['thread_id' => $working->id, 'files_count' => 3]);

        $failed = Thread::factory()->child($root)->stopped()->create([
            'title' => 'Swap the query adapter',
            'name' => 'query-adapter',
            'model' => 'sonnet',
            'created_by_user_id' => $owner->id,
        ]);
        StackThreadMessage::factory()->create([
            'root_thread_id' => $root->id,
            'from_thread_id' => $failed->id,
            'to_thread_id' => $root->id,
            'kind' => StackThreadMessageKind::Failure,
            'text' => 'The adapter tests fail on the new client.',
            'created_at' => '2026-11-15 11:20:00',
        ]);

        $research = Thread::factory()->child($root)->create([
            'title' => 'Read the index settings',
            'name' => 'index-settings',
            'kind' => ThreadKind::Research,
            'model' => 'haiku',
            'result_summary' => 'The analyzer needs the ascii folding filter.',
            'created_by_user_id' => $owner->id,
        ]);
        StackThreadMessage::factory()->create([
            'root_thread_id' => $root->id,
            'from_thread_id' => $research->id,
            'to_thread_id' => $root->id,
            'kind' => StackThreadMessageKind::Result,
            'text' => 'The analyzer needs the ascii folding filter.',
            'created_at' => '2026-11-15 11:40:00',
        ]);

        // Seven runs, the first started at 10:30; only the working child's last run still runs.
        $runs = [
            [$root, '10:30', '10:45', 0.30],
            [$root, '10:50', '11:05', 0.15],
            [$working, '11:00', '11:20', 0.20],
            [$working, '11:50', null, 0.10],
            [$failed, '10:55', '11:15', 0.20],
            [$failed, '11:16', '11:20', 0.10],
            [$research, '11:10', '11:40', 0.20],
        ];

        foreach ($runs as [$thread, $startedAt, $finishedAt, $cost]) {
            $task = YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $owner->id]);
            TaskRun::factory()->create([
                'yak_task_id' => $task->id,
                'thread_id' => $thread->id,
                'started_at' => CarbonImmutable::parse("2026-11-15 {$startedAt}:00"),
                'finished_at' => $finishedAt === null ? null : CarbonImmutable::parse("2026-11-15 {$finishedAt}:00"),
                'turn_state' => $finishedAt === null ? TurnState::Running : TurnState::Completed,
                'recorded_cost_usd' => $cost,
            ]);
        }

        return new self($owner, $recipient, $root->refresh(), $working->refresh(), $failed->refresh(), $research->refresh());
    }
}
