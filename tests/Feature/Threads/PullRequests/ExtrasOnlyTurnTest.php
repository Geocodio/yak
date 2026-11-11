<?php

use App\Enums\TaskRunKind;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Jobs\AfterTurn\FinishRetryTurnJob;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

function extraAheadOfOrigin(?FakeSandboxManager $shell = null): array
{
    config()->set('yak.channels.github.installation_id', 0);
    $shell ??= new FakeSandboxManager;
    $shell->setCommitCount(0);
    fakeAfterTurnCollaborators(shell: $shell);
    [$task, $run, $thread] = endedTurn(kind: TaskRunKind::Initial);
    $web = ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/web', 'sandbox_path' => '/extras/web', 'branch' => 'yak/x-web']);
    $sandboxes = app(SandboxManager::class);
    $sandboxes->queueExecResult(Process::result());
    $sandboxes->queueExecResult(Process::result('1'));
    $sandboxes->queueExecResult(Process::result());

    return [$task, $run, $web];
}

it('publishes an extra repo when the primary has no new commits', function (string $job, TaskRunKind $kind) {
    Queue::fake();
    [, $run, $web] = extraAheadOfOrigin();
    $run->forceFill(['kind' => $kind])->save();

    runFinisher($run, $job);

    expect($web->refresh()->ci_status)->toBe('running');
})->with([
    'initial' => [FinishRunYakTurnJob::class, TaskRunKind::Initial],
    'follow-up' => [FinishFollowUpTurnJob::class, TaskRunKind::FollowUp],
    'retry' => [FinishRetryTurnJob::class, TaskRunKind::Retry],
]);

it('publishes an extra repo before the dirty primary fails the turn', function () {
    Queue::fake();
    $shell = new class extends FakeSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null, array $env = []): ProcessResult
        {
            parent::run($containerName, $command, $timeout, $asRoot, $input, $output, $env);

            return str_contains($command, 'git status --porcelain') ? Process::result(' M a.php') : parent::run($containerName, $command);
        }
    };
    [, $run, $web] = extraAheadOfOrigin($shell);

    runFinisher($run, FinishRunYakTurnJob::class);

    expect($web->refresh()->ci_status)->toBe('running');
});
