<?php

use App\Actions\RecordPullRequestOutcome;
use App\Channels\GitHub\AppService;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Jobs\SendNotificationJob;
use App\Models\CiFailureClaim;
use App\Models\CiJobFailure;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\CiFailure\CiFailureNotificationGate;
use App\Services\CiFailure\CiFailureOutcome;
use App\Services\DirectMessageRecipients;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function outcomeLine(string $outcome): string
{
    return "Done.\n\nCI-FAILURE-OUTCOME: {$outcome}";
}

/**
 * @return array{0: YakTask, 1: CiFailureClaim}
 */
function ciFailureTaskWithClaim(array $claim = [], array $task = []): array
{
    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets', 'default_branch' => 'main']);

    $yakTask = YakTask::factory()->create($task + [
        'repo' => 'acme/widgets',
        'source' => 'ci-failure',
        'status' => TaskStatus::Running,
        'context' => json_encode(['failures' => [['branch' => 'a', 'head_sha' => 'sha-a', 'job_url' => 'https://github.com/acme/widgets/actions/runs/1/job/10']]]),
    ]);

    return [$yakTask, CiFailureClaim::factory()->create($claim + ['yak_task_id' => $yakTask->id])];
}

function finishCiFailureTask(YakTask $task, TaskStatus $status, ?string $summary): void
{
    $task->updateQuietly(['status' => $status, 'result_summary' => $summary, 'completed_at' => now()]);
    event(new TaskStatusChanged($task->fresh(), TaskStatus::Running, $status));
}

it('parses each outcome line', function (string $summary, CiFailureOutcome $expected) {
    expect(CiFailureOutcome::fromSummary($summary))->toBe($expected);
})->with([
    'fixed' => [outcomeLine('fixed'), CiFailureOutcome::Fixed],
    'unverified' => [outcomeLine('unverified_fix'), CiFailureOutcome::UnverifiedFix],
    'already fixed' => [outcomeLine('already_fixed'), CiFailureOutcome::AlreadyFixed],
    'not shared' => [outcomeLine('not_shared'), CiFailureOutcome::NotShared],
    'covered' => [outcomeLine('covered_by_pr #12'), CiFailureOutcome::CoveredByPullRequest],
    'cannot fix' => [outcomeLine('cannot_fix'), CiFailureOutcome::CannotFix],
    'last line wins over a quoted example' => ["CI-FAILURE-OUTCOME: fixed\nthen\nCI-FAILURE-OUTCOME: not_shared", CiFailureOutcome::NotShared],
]);

it('treats a missing outcome line as cannot_fix', function (?string $summary) {
    expect(CiFailureOutcome::fromSummary($summary))->toBe(CiFailureOutcome::CannotFix);
})->with([null, 'all done', 'CI-FAILURE-OUTCOME: covered_by_pr', 'CI-FAILURE-OUTCOME: maybe']);

it('backs off 24h, 48h, then weekly on repeated cannot_fix', function () {
    [$task, $claim] = ciFailureTaskWithClaim();

    foreach ([24, 48, 168, 168] as $index => $hours) {
        $this->travelTo(now()->addMinute());
        Cache::flush();
        finishCiFailureTask($task->fresh(), TaskStatus::Success, outcomeLine('cannot_fix'));

        $claim->refresh();
        expect($claim->attempt_count)->toBe($index + 1)
            ->and($claim->last_outcome)->toBe('cannot_fix')
            ->and((int) round(now()->diffInHours($claim->blocked_until, true)))->toBe($hours);
    }
});

it('does not count the same transition twice', function () {
    [$task, $claim] = ciFailureTaskWithClaim();

    finishCiFailureTask($task, TaskStatus::Success, outcomeLine('cannot_fix'));
    event(new TaskStatusChanged($task->fresh(), TaskStatus::Running, TaskStatus::Success));

    expect($claim->fresh()->attempt_count)->toBe(1);
});

it('records the default head as fix_merge_sha on already_fixed', function () {
    $this->mock(AppService::class)->shouldReceive('getBranchHeadSha')->once()->andReturn('default-head');
    [$task, $claim] = ciFailureTaskWithClaim();

    finishCiFailureTask($task, TaskStatus::Success, outcomeLine('already_fixed'));

    expect($claim->fresh()->fix_merge_sha)->toBe('default-head')
        ->and($claim->fresh()->last_outcome)->toBe('already_fixed')
        ->and($claim->fresh()->attempt_count)->toBe(0);
});

it('resolves evidence rows on not_shared', function () {
    [$task] = ciFailureTaskWithClaim();
    $evidence = CiJobFailure::factory()->create(['repo' => 'acme/widgets', 'job_url' => 'https://github.com/acme/widgets/actions/runs/1/job/10']);
    $other = CiJobFailure::factory()->create(['repo' => 'acme/widgets', 'job_url' => 'https://github.com/acme/widgets/actions/runs/2/job/20']);

    finishCiFailureTask($task, TaskStatus::Success, outcomeLine('not_shared'));

    expect($evidence->fresh()->resolution)->toBe('not_shared')
        ->and($evidence->fresh()->resolved_at)->not->toBeNull()
        ->and($other->fresh()->resolved_at)->toBeNull();
});

it('writes skipped_pr_url on covered_by_pr', function () {
    [$task, $claim] = ciFailureTaskWithClaim();

    finishCiFailureTask($task, TaskStatus::Success, outcomeLine('covered_by_pr #77'));

    expect($claim->fresh()->skipped_pr_url)->toBe('https://github.com/acme/widgets/pull/77');
});

it('sets fix_merge_sha when the ci-failure PR merges', function () {
    [$task, $claim] = ciFailureTaskWithClaim(task: ['pr_url' => 'https://github.com/acme/widgets/pull/5']);

    app(RecordPullRequestOutcome::class)->record($task->pr_url, merged: true, mergeCommitSha: 'merge-sha');

    expect($claim->fresh()->fix_merge_sha)->toBe('merge-sha');
});

it('notifies on the first failure and on a changed outcome only', function () {
    [$task, $claim] = ciFailureTaskWithClaim();

    finishCiFailureTask($task, TaskStatus::Failed, outcomeLine('cannot_fix'));
    expect(CiFailureNotificationGate::shouldNotify($task->fresh()))->toBeTrue();

    $this->travelTo(now()->addMinute());
    finishCiFailureTask($task->fresh(), TaskStatus::Failed, outcomeLine('cannot_fix'));
    expect(CiFailureNotificationGate::shouldNotify($task->fresh()))->toBeFalse();

    $this->travelTo(now()->addMinute());
    finishCiFailureTask($task->fresh(), TaskStatus::Failed, outcomeLine('fixed'));
    expect(CiFailureNotificationGate::shouldNotify($task->fresh()))->toBeTrue();
});

it('does not notify for an infrastructure failure but still backs off', function () {
    [$task, $claim] = ciFailureTaskWithClaim();

    finishCiFailureTask($task, TaskStatus::Failed, null);

    expect($claim->fresh()->last_outcome)->toBe('infrastructure')
        ->and($claim->fresh()->attempt_count)->toBe(1)
        ->and($claim->fresh()->blocked_until)->not->toBeNull()
        ->and(CiFailureNotificationGate::shouldNotify($task->fresh()))->toBeFalse();
});

it('still records the outcome when the default head lookup throws', function () {
    $this->mock(AppService::class)->shouldReceive('getBranchHeadSha')->andThrow(new RuntimeException('timeout'));
    [$task, $claim] = ciFailureTaskWithClaim();

    finishCiFailureTask($task, TaskStatus::Success, outcomeLine('already_fixed'));

    expect($claim->fresh()->last_outcome)->toBe('already_fixed')
        ->and($claim->fresh()->fix_merge_sha)->toBeNull();
});

it('does not re-notify a repeated cannot_fix that ended as Success', function () {
    [$task] = ciFailureTaskWithClaim();

    finishCiFailureTask($task, TaskStatus::Success, outcomeLine('cannot_fix'));
    expect(CiFailureNotificationGate::shouldNotify($task->fresh()))->toBeTrue();

    $this->travelTo(now()->addMinute());
    finishCiFailureTask($task->fresh(), TaskStatus::Success, outcomeLine('cannot_fix'));
    expect(CiFailureNotificationGate::shouldNotify($task->fresh()))->toBeFalse();
});

it('skips the notification job when the gate says no and sends otherwise', function (NotificationType $type, TaskStatus $status, string $summary) {
    [$task] = ciFailureTaskWithClaim();
    $task->updateQuietly(['status' => $status, 'result_summary' => $summary]);

    CiFailureNotificationGate::remember($task, false);
    $this->mock(DirectMessageRecipients::class)->shouldNotReceive('for');
    app()->call([new SendNotificationJob($task->fresh(), $type, 'msg'), 'handle']);

    CiFailureNotificationGate::remember($task, true);
    $this->mock(DirectMessageRecipients::class)->shouldReceive('for')->once()->andReturn([]);
    app()->call([new SendNotificationJob($task->fresh(), $type, 'msg'), 'handle']);
})->with([
    'error' => [NotificationType::Error, TaskStatus::Failed, 'x'],
    'result cannot_fix' => [NotificationType::Result, TaskStatus::Success, 'CI-FAILURE-OUTCOME: cannot_fix'],
]);

it('does not gate a result that carries a fix', function () {
    [$task] = ciFailureTaskWithClaim();
    $task->updateQuietly(['status' => TaskStatus::Success, 'result_summary' => 'CI-FAILURE-OUTCOME: fixed']);

    CiFailureNotificationGate::remember($task, false);
    $this->mock(DirectMessageRecipients::class)->shouldReceive('for')->once()->andReturn([]);
    app()->call([new SendNotificationJob($task->fresh(), NotificationType::Result, 'msg'), 'handle']);
});

it('sets fix_merge_sha when the reconciler records a merged ci-failure PR', function () {
    config()->set('yak.channels.github.installation_id', 99);
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 'ghs', 'expires_at' => now()->addHour()]);
    [$task, $claim] = ciFailureTaskWithClaim(task: ['pr_url' => 'https://github.com/acme/widgets/pull/1', 'pr_number' => 1, 'status' => TaskStatus::Success]);

    Http::fake([
        'api.github.com/repos/acme/widgets/pulls/1/commits*' => Http::response([]),
        'api.github.com/repos/acme/widgets/pulls/1' => Http::response(['state' => 'closed', 'merged' => true, 'merged_at' => '2026-09-15T10:00:00Z', 'closed_at' => '2026-09-15T10:00:00Z', 'merge_commit_sha' => 'reconciled-sha']),
        'api.github.com/*' => Http::response([]),
    ]);

    $this->artisan('yak:reconcile-pr-state')->assertSuccessful();

    expect($claim->fresh()->fix_merge_sha)->toBe('reconciled-sha');
});
