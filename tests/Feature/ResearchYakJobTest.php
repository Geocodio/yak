<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../Support/FinishTurnHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('artifacts');
    Queue::fake();
});

test('research completes with the answer, no branch and no push', function () {
    $shell = new FakeSandboxManager;

    $task = researchTurn('The middleware is idempotent.', ['source' => 'manual'], [], $shell);

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toBe('The middleware is idempotent.')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->branch_name)->toBeNull()
        ->and($shell->commandsMatching('git push'))->toBe([])
        ->and($shell->commandsMatching('git checkout -b'))->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

test('research fetches and resets the default branch before the turn', function () {
    $shell = new FakeSandboxManager;

    researchTurn('Done', ['source' => 'manual'], ['default_branch' => 'develop'], $shell);

    $commands = $shell->commands;
    $fetch = collect($commands)->search(fn ($command) => str_contains($command, 'git fetch origin develop'));
    $reset = collect($commands)->search(fn ($command) => str_contains($command, 'git reset --hard origin/develop'));
    expect($fetch)->not->toBeFalse()->and($reset)->toBeGreaterThan($fetch);
});

function reportShell(): FakeSandboxManager
{
    return new class extends FakeSandboxManager
    {
        public function fileExists(string $containerName, string $path): bool
        {
            return str_contains($path, 'research.html');
        }

        public function pullFile(string $containerName, string $remotePath, string $localPath): void
        {
            if (! is_dir(dirname($localPath))) {
                mkdir(dirname($localPath), 0755, true);
            }
            file_put_contents($localPath, '<html>research</html>');
        }
    };
}

test('research collects the HTML report from the sandbox when present', function () {
    $task = researchTurn('Research complete', ['source' => 'manual'], [], reportShell());

    $artifact = Artifact::where('yak_task_id', $task->id)->first();
    expect($artifact)->not->toBeNull()
        ->and($artifact->type)->toBe('research')
        ->and($artifact->filename)->toBe('research.html')
        ->and($artifact->disk_path)->toBe("{$task->id}/research.html");
});

test('research without a report completes with no artifact', function () {
    $task = researchTurn('Research complete', ['source' => 'manual']);

    expect($task->status)->toBe(TaskStatus::Success)
        ->and(Artifact::where('yak_task_id', $task->id)->exists())->toBeFalse();
});

test('research posts the summary and the report link as a Slack thread reply', function () {
    config(['yak.channels.slack.bot_token' => 'xoxb-test-token', 'yak.channels.slack.signing_secret' => 'slack-secret']);

    $task = researchTurn('Analysis shows three bottlenecks', ['source' => 'slack', 'slack_channel' => 'C12345', 'slack_thread_ts' => '1234567890.123456'], [], reportShell());

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => str_contains($job->message, 'Analysis shows three bottlenecks')
        && str_contains($job->message, "/artifacts/{$task->public_id}/viewer/research.html"));
});

test('research that asks parks the task and collects no artifact', function () {
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('scope')->toArray()]]) . "\n```";

    $task = researchTurn("One thing.\n\n{$block}", ['source' => 'manual'], [], reportShell());

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and(Artifact::where('yak_task_id', $task->id)->exists())->toBeFalse();
});

test('a research job runs on the yak-claude queue', function () {
    expect((new ResearchYakJob(YakTask::factory()->make()))->queue)->toBe('yak-claude');
});
