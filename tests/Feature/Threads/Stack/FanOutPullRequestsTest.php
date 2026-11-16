<?php

use App\Actions\RecordPullRequestOutcome;
use App\Channels\GitHub\AppService;
use App\DataTransferObjects\YakToolCall;
use App\Enums\RepositoryRole;
use App\Enums\ThreadKind;
use App\Enums\ThreadStatus;
use App\Models\FollowUpPendingComment;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use App\Services\Stacks\StackBranchCleanup;
use App\Services\Stacks\StackListing;
use App\Services\Stacks\StackToolRouter;
use App\Services\Threads\PullRequests\MergeOrder;
use App\Services\Threads\PullRequests\SiblingBlock;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config()->set('yak.channels.github', ['app_id' => '123', 'private_key' => 'key', 'webhook_secret' => 'secret', 'app_bot_login' => 'yak-bot[bot]', 'installation_id' => 0]);
    config()->set('yak.followup.github_prefixes', '/yak');
    (new ChannelServiceProvider(app()))->boot();
    Queue::fake();
    Http::fake();
    $this->withoutVite();

    foreach (['acme-api' => 'acme/api', 'acme-web' => 'acme/web', 'acme-docs' => 'acme/docs'] as $slug => $full) {
        Repository::factory()->create(['slug' => $slug, 'github_full_name' => $full]);
    }

    $this->root = Thread::factory()->create(['repo' => 'acme-api', 'title' => 'Rename widget']);
    ThreadRepository::factory()->for($this->root)->withPullRequest(4)->create(['repo' => 'acme-api', 'branch' => 'yak/root']);
    $this->web = repoFanOutChild($this->root, 'acme-web', 9, 'web-copy');
    $this->docs = repoFanOutChild($this->root, 'acme-docs', 3, 'docs-copy');
});

function repoFanOutChild(Thread $root, string $repo, int $number, string $name): Thread
{
    $child = Thread::factory()->child($root)->create(['repo' => $repo, 'name' => $name, 'branch' => "yak/root/{$name}"]);
    ThreadRepository::factory()->for($child)->withPullRequest($number)->create(['repo' => $repo, 'role' => RepositoryRole::Primary, 'branch' => $child->branch, 'ci_status' => 'passed', 'pull_request_url' => "https://github.com/{$repo}/pull/{$number}"]);
    YakTask::factory()->create(['thread_id' => $child->id, 'repo' => $repo, 'pr_url' => "https://github.com/{$repo}/pull/{$number}", 'pr_number' => $number]);

    return $child;
}

it('starts a turn in the child, not the parent, for a comment on a child pull request', function () {
    $payload = [
        'action' => 'created',
        'issue' => ['number' => 9, 'pull_request' => ['html_url' => 'https://github.com/acme-web/pull/9']],
        'comment' => ['id' => 5, 'body' => '/yak tighten the copy', 'user' => ['login' => 'sam', 'type' => 'User']],
        'repository' => ['full_name' => 'acme/web'],
    ];
    YakTask::query()->where('thread_id', $this->web->id)->update(['pr_url' => 'https://github.com/acme-web/pull/9']);

    $this->postJson('/webhooks/github', $payload, ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'), 'X-GitHub-Event' => 'issue_comment'])->assertOk();

    $pending = FollowUpPendingComment::query()->sole();

    expect(YakTask::query()->find($pending->yak_task_id)->thread_id)->toBe($this->web->id);
});

it('lists the parent and every repo child in one sibling block in spawn order', function () {
    $block = app(SiblingBlock::class)->render($this->root, $this->root->primaryRepository);
    $forChild = app(SiblingBlock::class)->render($this->docs, $this->docs->primaryRepository);

    expect($block)->toContain('spans 3 repositories')
        ->and(strpos($block, 'acme/api#4'))->toBeLessThan(strpos($block, 'acme/web#9'))
        ->and(strpos($block, 'acme/web#9'))->toBeLessThan(strpos($block, 'acme/docs#3'))
        ->and($forChild)->toContain('acme/web#9')
        ->and(app(MergeOrder::class)->forThread($this->root)->pluck('pull_request_number')->all())->toBe([4, 9, 3]);
});

it('shows repo and pull request for repo children in the stack listing', function () {
    $rows = collect(StackListing::for($this->root))->keyBy('name');

    expect($rows[$this->root->name]['repo'])->toBeNull()
        ->and($rows['web-copy']['repo'])->toBe('acme-web')
        ->and($rows['web-copy']['pullRequestUrl'])->toBe('https://github.com/acme-web/pull/9')
        ->and($rows['web-copy']['ciStatus'])->toBe('passed');

    $text = app(StackToolRouter::class)->handle($this->root, new YakToolCall('c1', 'list_threads', []))->content;

    expect($text)->toContain('web-copy (implement, queued) in acme-web, https://github.com/acme-web/pull/9');
});

it('closes only the child when its pull request merges', function () {
    $this->mock(ThreadSandbox::class)->shouldIgnoreMissing();

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme-web/pull/9', merged: true);

    expect($this->web->refresh()->status)->toBe(ThreadStatus::Closed)
        ->and($this->root->refresh()->status)->toBe(ThreadStatus::Open)
        ->and($this->docs->refresh()->status)->toBe(ThreadStatus::Open);
});

it('deletes only branches in the root repo when the stack closes', function () {
    Thread::factory()->child($this->root)->create(['repo' => 'acme-api', 'kind' => ThreadKind::Implement, 'branch' => 'yak/root/same']);
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('deleteBranch')->once()->with(Mockery::any(), 'acme/api', 'yak/root/same')->andReturn(true);
    $cleanup = new StackBranchCleanup($github);

    expect($cleanup->deleteChildBranches($this->root))->toBe(1);
});

it('keeps a private fan-out hidden from a second user and an admin', function () {
    $world = VisibilityWorld::build();
    $world->child->update(['repo' => 'acme-web']);
    ThreadRepository::query()->where('thread_id', $world->child->id)->update(['pull_request_url' => 'https://github.com/acme-web/pull/9']);

    foreach (['other', 'admin'] as $stranger) {
        $this->actingAs($world->{$stranger})->getJson("/threads/{$world->thread->public_id}/stack")->assertNotFound();
    }
});
