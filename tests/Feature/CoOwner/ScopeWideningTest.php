<?php

use App\Enums\CoOwnerRequestStatus;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\PostScopeWideningCommentJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function prFiles(string ...$names): array
{
    return ['api.github.com/repos/o/r/pulls/7/files*' => Http::response(array_map(fn (string $name): array => ['filename' => $name, 'status' => 'modified'], $names))];
}

function commentBodies(): array
{
    return Http::recorded(fn ($request) => str_ends_with($request->url(), '/issues/7/comments'))
        ->map(fn ($pair) => $pair[0]['body'])->values()->all();
}

beforeEach(function () {
    configureFakeGitHubApp();
    $this->repository = Repository::factory()->create(['github_full_name' => 'o/r', 'default_branch' => 'main']);
    $this->task = YakTask::factory()->create(['repo' => $this->repository->slug, 'branch_name' => 'yak/x']);
    $this->request = CoOwnerRequest::factory()->create([
        'task_id' => $this->task->id,
        'root_task_id' => $this->task->id,
        'repository_id' => $this->repository->id,
        'status' => CoOwnerRequestStatus::Approved,
        'decided_by_login' => 'alice',
        'matched_areas' => [['name' => 'API middleware and HTTP kernel', 'risk' => 'high', 'rationale' => '', 'paths' => ['app/Http/Kernel.php']]],
    ]);
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n", 'risk-profile.yml' => riskProfileYaml()]);
});

it('comments when a follow-up reaches a new held area', function () {
    Http::fake(gitHubTokenFake() + prFiles('app/Http/Kernel.php', 'lib/legacy-shim/a.php') + ['*' => Http::response([], 201)]);

    PostScopeWideningCommentJob::dispatchSync($this->task, 7, 'abcdef0123456');

    $bodies = commentBodies();
    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('Heads up @alice: the latest follow-up also touches Legacy geocoder shim, which')
        ->and($bodies[0])->toContain('Pushed in abcdef0.')
        ->and($bodies[0])->toContain('- `lib/legacy-shim/a.php` high')
        ->and($bodies[0])->not->toContain('Kernel.php')
        ->and($this->request->fresh()->notified_areas)->toBe(['Legacy geocoder shim']);
});

it('does not comment for areas the approval covered', function () {
    Http::fake(gitHubTokenFake() + prFiles('app/Http/Kernel.php') + ['*' => Http::response([], 201)]);

    PostScopeWideningCommentJob::dispatchSync($this->task, 7, 'abcdef0123456');

    expect(commentBodies())->toBe([]);
});

it('comments once per new area', function () {
    Http::fake(gitHubTokenFake() + prFiles('lib/legacy-shim/a.php') + ['*' => Http::response([], 201)]);

    PostScopeWideningCommentJob::dispatchSync($this->task, 7, 'abcdef0123456');
    PostScopeWideningCommentJob::dispatchSync($this->task, 7, '1234567890abc');

    expect(commentBodies())->toHaveCount(1);
});

it('omits the mention when the approver login is not a GitHub login', function () {
    $this->request->update(['decided_by_login' => 'bad login!']);
    Http::fake(gitHubTokenFake() + prFiles('lib/legacy-shim/a.php') + ['*' => Http::response([], 201)]);

    PostScopeWideningCommentJob::dispatchSync($this->task, 7, 'abcdef0123456');

    expect(commentBodies()[0])->toStartWith('Heads up: the latest');
});

it('logs a warning and retries when the comment fails', function () {
    Http::fake(gitHubTokenFake() + prFiles('lib/legacy-shim/a.php') + ['*' => Http::response([], 500)]);

    expect(fn () => PostScopeWideningCommentJob::dispatchSync($this->task, 7, 'abcdef0123456'))->toThrow(RuntimeException::class);

    expect($this->task->logs()->where('level', 'warning')->where('message', 'like', 'Could not post the scope-widening comment on PR #7:%')->exists())->toBeTrue()
        ->and($this->request->fresh()->notified_areas)->toBe([]);
});

it('skips the scope check when no request was approved', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::Expired]);
    Http::fake(gitHubTokenFake() + prFiles('lib/legacy-shim/a.php') + ['*' => Http::response([], 201)]);

    PostScopeWideningCommentJob::dispatchSync($this->task, 7, 'abcdef0123456');

    expect(commentBodies())->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/pulls/7/files'));
});

it('dispatches the scope check when a follow-up pushes to an existing PR', function () {
    Bus::fake([PostScopeWideningCommentJob::class]);
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/o/r/pulls?*' => Http::response([['number' => 7, 'html_url' => 'https://github.com/o/r/pull/7']]),
        'api.github.com/repos/o/r/branches/*' => Http::response(['commit' => ['sha' => 'abcdef0123456']]),
        '*' => Http::response([], 201),
    ]);

    CreatePullRequestJob::dispatchSync($this->task);

    Bus::assertDispatched(PostScopeWideningCommentJob::class, fn ($job) => $job->prNumber === 7 && $job->headSha === 'abcdef0123456');
});
