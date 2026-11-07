<?php

use App\Channels\GitHub\AppService;
use App\Channels\Slack\PreviewRepairMessenger;
use App\Enums\PreviewRepairVerdict;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\SendPreviewRepairMessagesJob;
use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'yak.channels.slack.bot_token' => 'xoxb-test',
        'yak.channels.slack.feed_channel' => 'CFEED',
        'yak.channels.github.installation_id' => 1,
    ]);
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
    $this->github = Mockery::mock(AppService::class);
    $this->github->shouldReceive('getPullRequest')->andReturn(['user' => ['login' => 'author']])->byDefault();
    $this->app->instance(AppService::class, $this->github);

    $this->author = User::factory()->withGitHub(7, 'author')->create(['slack_user_id' => 'UAUTHOR']);
    $this->repository = Repository::factory()->create(['github_full_name' => 'acme/site', 'slug' => 'acme/site', 'name' => 'site']);
    $task = YakTask::factory()->create(['source' => 'preview-repair', 'repo' => 'acme/site', 'pr_url' => 'https://github.com/acme/site/pull/101', 'pr_number' => 101]);
    $this->previewRepair = PreviewRepair::factory()->for($this->repository)->create([
        'yak_task_id' => $task->id,
        'verdict' => PreviewRepairVerdict::Recipe,
        'cause' => '`.yak/preview.yml` still runs `docker build`, but the Dockerfile was removed in `d6dd223`.',
    ]);
});

function attachFailedPreview(PreviewRepair $previewRepair, string $branch, int $pullRequestNumber = 92): BranchDeployment
{
    $deployment = BranchDeployment::factory()->for($previewRepair->repository)->failed()->create(['branch_name' => $branch, 'pr_number' => $pullRequestNumber]);
    $previewRepair->deployments()->attach($deployment->id, ['failed_at' => now()]);

    return $deployment;
}

/**
 * @return list<array<string, mixed>>
 */
function slackPosts(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), 'chat.postMessage'))
        ->map(fn (Request $request) => $request->data())
        ->values()
        ->all();
}

it('sends one direct message per user listing all their previews', function () {
    attachFailedPreview($this->previewRepair, 'update-pricing-page-design');
    attachFailedPreview($this->previewRepair, 'gdpr-subprocessors', 97);

    app(PreviewRepairMessenger::class)->repairOpened($this->previewRepair);

    $directMessages = collect(slackPosts())->where('channel', 'UAUTHOR');
    $body = json_encode($directMessages->first()['blocks'], JSON_UNESCAPED_SLASHES);
    expect($directMessages)->toHaveCount(1)
        ->and($body)->toContain('update-pricing-page-design')
        ->and($body)->toContain('gdpr-subprocessors')
        ->and($body)->toContain('https://github.com/acme/site/pull/101')
        ->and($this->previewRepair->attachments()->whereNull('notified_at')->count())->toBe(0)
        ->and($this->previewRepair->fresh()->last_notified_at)->not->toBeNull();
});

it('posts the repair to the feed channel', function () {
    attachFailedPreview($this->previewRepair, 'update-pricing-page-design');

    app(PreviewRepairMessenger::class)->repairOpened($this->previewRepair);

    $feed = collect(slackPosts())->firstWhere('channel', 'CFEED');
    expect($feed['text'])->toContain('Previews are broken on site')
        ->and(json_encode($feed['blocks'], JSON_UNESCAPED_SLASHES))->toContain('https://github.com/acme/site/pull/101');
});

it('messages only the pull request author for a branch verdict', function () {
    $this->previewRepair->update(['verdict' => PreviewRepairVerdict::Branch, 'cause' => 'The migration in this branch fails.']);
    attachFailedPreview($this->previewRepair, 'eng-1740-quote-discounts');

    app(PreviewRepairMessenger::class)->verdictReached($this->previewRepair);

    $posts = collect(slackPosts());
    expect($posts->pluck('channel')->all())->toBe(['UAUTHOR'])
        ->and(json_encode($posts->first()['blocks'], JSON_UNESCAPED_SLASHES))->toContain('The migration in this branch fails.')
        ->and(json_encode($posts->first()['blocks'], JSON_UNESCAPED_SLASHES))->toContain('did not open a pull request');
});

it('posts infrastructure and unknown verdicts only to the feed', function (PreviewRepairVerdict $verdict) {
    $this->previewRepair->update(['verdict' => $verdict]);
    attachFailedPreview($this->previewRepair, 'pricing');

    app(PreviewRepairMessenger::class)->verdictReached($this->previewRepair);

    expect(collect(slackPosts())->pluck('channel')->all())->toBe(['CFEED']);
})->with([PreviewRepairVerdict::Infrastructure, PreviewRepairVerdict::Unknown]);

it('skips silently without slack configuration', function (string $key) {
    config([$key => '']);
    attachFailedPreview($this->previewRepair, 'pricing');

    app(PreviewRepairMessenger::class)->repairOpened($this->previewRepair);
    app(PreviewRepairMessenger::class)->capReached($this->repository);

    $channels = collect(slackPosts())->pluck('channel');
    expect($key === 'yak.channels.slack.bot_token' ? $channels->all() : $channels->contains('CFEED'))->toBeFalsy();
})->with(['yak.channels.slack.bot_token', 'yak.channels.slack.feed_channel']);

it('escapes untrusted error text', function () {
    $this->previewRepair->update(['verdict' => PreviewRepairVerdict::Unknown, 'cause' => null, 'error_excerpt' => '<!channel> *x* <http://evil|click>']);
    attachFailedPreview($this->previewRepair, 'pricing');

    app(PreviewRepairMessenger::class)->verdictReached($this->previewRepair);

    $body = json_encode(collect(slackPosts())->first()['blocks'], JSON_UNESCAPED_SLASHES);
    expect($body)->not->toContain('<!channel>')->and($body)->not->toContain('<http://evil|click>');
});

it('posts when a repair pull request fails its own preview', function () {
    $deployment = BranchDeployment::factory()->for($this->repository)->failed('deploy: boom')->create(['branch_name' => 'yak/preview-repair-1']);
    $this->previewRepair->task->update(['branch_name' => 'yak/preview-repair-1']);

    app(PreviewRepairMessenger::class)->repairPreviewFailed($deployment);

    $feed = collect(slackPosts())->firstWhere('channel', 'CFEED');
    expect($feed['text'])->toContain('did not start its own preview');
});

it('dispatches the repair message once the pull request exists', function () {
    Queue::fake([SendPreviewRepairMessagesJob::class]);
    $task = $this->previewRepair->task;

    $method = new ReflectionMethod(CreatePullRequestJob::class, 'recordOpened');
    $method->invoke(new CreatePullRequestJob($task), 101, now());

    Queue::assertPushed(SendPreviewRepairMessagesJob::class, fn (SendPreviewRepairMessagesJob $job) => $job->previewRepairId === $this->previewRepair->id
        && $job->event === 'repair_opened');
});
