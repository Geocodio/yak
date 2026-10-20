<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerGate;
use App\Services\CoOwner\CoOwnerResolver;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\RiskProfileVerdict;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function gateConfig(string $mode = 'enforce'): string
{
    return "version: 1\nco_owner_gate:\n  mode: {$mode}\n";
}

function gateCompare(array $files): array
{
    return ['api.github.com/repos/o/r/compare/*' => Http::response(['files' => $files])];
}

function gateFile(string $name, string $status = 'modified', array $extra = []): array
{
    return ['filename' => $name, 'status' => $status, 'additions' => 4, 'deletions' => 1, ...$extra];
}

beforeEach(function () {
    configureFakeGitHubApp();
    Bus::fake([SendCoOwnerMessagesJob::class]);
    $this->repository = Repository::factory()->create(['github_full_name' => 'o/r', 'default_branch' => 'main']);
    $this->task = YakTask::factory()->awaitingCi()->create(['repo' => $this->repository->slug, 'branch_name' => 'yak/x', 'started_by_user_id' => null, 'source' => 'slack', 'mode' => TaskMode::Fix]);
    fakeYakFiles(['config.yml' => gateConfig(), 'risk-profile.yml' => riskProfileYaml()]);
});

it('holds a change to a high area', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Middleware/Authenticate.php')]) + ['*' => Http::response([])]);

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request)->toBeInstanceOf(CoOwnerRequest::class)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner)
        ->and(CoOwnerRequest::count())->toBe(1);
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job) => $job->event === 'held');
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls'));
    Bus::assertNotDispatched(SendNotificationJob::class);
});

it('does not open the PR or notify a result when the job holds', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]) + ['api.github.com/repos/o/r/pulls*' => Http::response([])] + ['*' => Http::response([])]);

    CreatePullRequestJob::dispatchSync($this->task);

    expect($this->task->fresh()->pr_url)->toBeNull()
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls'));
});

it('holds a renamed file whose old path is in a held area', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('src/moved.php', 'renamed', ['previous_filename' => 'lib/legacy-shim/a.php'])]));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->matched_areas[0]['name'])->toBe('Legacy geocoder shim')
        ->and($request->matched_areas[0]['paths'])->toBe(['src/moved.php'])
        ->and($request->changed_files[0]['status'])->toBe('renamed')
        ->and($request->changed_files[0]['previousPath'])->toBe('lib/legacy-shim/a.php');
});

it('holds when the compare call fails', function () {
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/o/r/compare/*' => Http::response([], 500)]);

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toBe(['compare_failed'])
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner);
});

it('holds when the compare lists 300 files', function () {
    $files = array_map(fn (int $i): array => gateFile("docs/f{$i}.md"), range(1, 300));
    Http::fake(gitHubTokenFake() + gateCompare($files));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toBe(['listing']);
});

it('holds a change to .yak/config.yml even with no area', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('.yak/config.yml')]));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toContain('floor')
        ->and($request->matched_areas)->toBe([])
        ->and($request->changed_files[0]['isHeld'])->toBeTrue();
});

it('holds every change when enforce has no profile', function () {
    fakeYakFiles(['config.yml' => gateConfig()]);
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('docs/readme.md')]));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toBe(['no_profile'])
        ->and($request->profile_source)->toBe('none');
});

it('opens the PR when the change only touches medium and low areas', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('docs/readme.md')]));

    expect(app(CoOwnerGate::class)->hold($this->task, $this->repository))->toBeNull()
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and(CoOwnerRequest::count())->toBe(0);
});

it('opens the PR when the gate is off', function () {
    fakeYakFiles(['config.yml' => gateConfig('off'), 'risk-profile.yml' => riskProfileYaml()]);
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    expect(app(CoOwnerGate::class)->hold($this->task, $this->repository))->toBeNull();
});

it('opens the PR for exempt tasks', function (array $attributes) {
    $this->task->update($attributes);
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    expect(app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository))->toBeNull();
})->with([
    'setup' => [['mode' => TaskMode::Setup]],
    'review' => [['mode' => TaskMode::Review]],
    'research' => [['mode' => TaskMode::Research]],
    'sentry' => [['source' => 'sentry']],
    'flaky-test' => [['source' => 'flaky-test']],
]);

it('opens the PR without a Co-owner line when the requester has write access', function () {
    $user = User::factory()->withGitHub()->create();
    $this->task->update(['started_by_user_id' => $user->id]);
    $this->mock(GitHubWriteAccess::class, fn ($mock) => $mock->shouldReceive('hasWriteAccess')->andReturn(true));
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    expect(app(CoOwnerGate::class)->hold($this->task, $this->repository))->toBeNull();
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/compare/'));
});

it('holds when the requester cannot be mapped to a user', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    expect(app(CoOwnerGate::class)->hold($this->task, $this->repository))->not->toBeNull();
});

it('records the profile source, sha, areas, files and diff stat', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Middleware/A.php'), gateFile('tests/ATest.php', 'added')]));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->profile_source)->toBe('file')
        ->and($request->profile_commit_sha)->toBe('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($request->root_task_id)->toBe($this->task->id)
        ->and($request->status)->toBe(CoOwnerRequestStatus::Pending)
        ->and($request->matched_areas)->toBe([[
            'name' => 'API middleware and HTTP kernel', 'risk' => 'high', 'rationale' => 'Every request passes through these.', 'paths' => ['app/Http/Middleware/A.php'],
        ]])
        ->and(collect($request->changed_files)->pluck('status', 'path')->all())->toBe(['app/Http/Middleware/A.php' => 'modified', 'tests/ATest.php' => 'new'])
        ->and([$request->additions, $request->deletions, $request->file_count])->toBe([8, 2, 2]);
});

it('is idempotent for a task that is already held', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    $first = app(CoOwnerGate::class)->hold($this->task, $this->repository);
    $second = app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository);

    expect($second->id)->toBe($first->id)->and(CoOwnerRequest::count())->toBe(1);
});

it('holds with gate_error when something unexpected throws', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));
    $this->mock(RiskProfileVerdict::class, fn ($mock) => $mock->shouldReceive('evaluate')->andThrow(new RuntimeException('boom')));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toBe(['gate_error'])
        ->and($request->asked_user_ids)->toBe([]);
});

it('uses an empty CODEOWNERS and records the error when CODEOWNERS cannot be read', function () {
    fakeYakFiles(['config.yml' => gateConfig(), 'risk-profile.yml' => riskProfileYaml()]);
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]) + ['api.github.com/repos/o/r/contents/*' => Http::response([], 500)]);

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->ask_attempts)->toContain(['entry' => 'CODEOWNERS', 'kind' => 'codeowners', 'outcome' => 'error']);
});

it('takes the existing-PR path for follow-ups without running the gate', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/o/r/pulls*' => Http::response([['number' => 7, 'html_url' => 'https://github.com/o/r/pull/7']]),
        '*' => Http::response([]),
    ]);

    CreatePullRequestJob::dispatchSync($this->task);

    expect(CoOwnerRequest::count())->toBe(0)
        ->and($this->task->fresh()->pr_number)->toBe(7);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/compare/'));
});

it('gates a fix task whose external id starts with risk-profile-', function () {
    $this->task->update(['external_id' => 'risk-profile-abc']);
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    expect(app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository))->not->toBeNull();
});

it('holds an exempt task that touches a config floor path', function () {
    $this->task->update(['source' => 'sentry']);
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('AGENTS.md')]));

    $request = app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository);

    expect($request->hold_reasons)->toBe(['floor']);
});

it('opens the PR for an exempt task that touches no floor path', function () {
    $this->task->update(['source' => 'sentry']);
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    expect(app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository))->toBeNull();
});

it('holds an exempt task when the compare fails', function () {
    $this->task->update(['mode' => TaskMode::Research]);
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/o/r/compare/*' => Http::response([], 500)]);

    expect(app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository)->hold_reasons)->toBe(['compare_failed']);
});

it('records an error attempt when the owners cannot be resolved', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));
    $this->mock(CoOwnerResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andThrow(new RuntimeException('boom')));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->ask_attempts)->toContain(['entry' => 'owners', 'kind' => 'codeowners', 'outcome' => 'error']);
});

it('dispatches the messages job once for a repeated hold', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Kernel.php')]));

    app(CoOwnerGate::class)->hold($this->task, $this->repository);
    app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository);

    Bus::assertDispatchedTimes(SendCoOwnerMessagesJob::class, 1);
});

it('holds an exempt task whose compare lists 300 files', function () {
    $this->task->update(['source' => 'sentry']);
    Http::fake(gitHubTokenFake() + gateCompare(array_map(fn (int $i): array => gateFile("docs/f{$i}.md"), range(1, 300))));

    expect(app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository)->hold_reasons)->toBe(['listing']);
});

it('supersedes a stale pending request when the task is judged again after a retry', function () {
    Http::fake(gitHubTokenFake() + gateCompare([gateFile('app/Http/Middleware/Authenticate.php')]) + ['*' => Http::response([])]);
    $first = app(CoOwnerGate::class)->hold($this->task, $this->repository);
    YakTask::whereKey($this->task->id)->update(['status' => TaskStatus::AwaitingCi]);

    $second = app(CoOwnerGate::class)->hold($this->task->fresh(), $this->repository);

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->status)->toBe(CoOwnerRequestStatus::Superseded)
        ->and($second->status)->toBe(CoOwnerRequestStatus::Pending)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner);
});
