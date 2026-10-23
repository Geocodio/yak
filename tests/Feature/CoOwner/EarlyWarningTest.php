<?php

use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\EarlyWarning;
use App\Services\CoOwner\EarlyWarningState;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\CoOwner\RiskAreaGuesser;
use App\Services\CoOwner\TaskRequester;

function earlyWarningFixture(array $taskAttributes = [], string $mode = 'enforce', ?string $guessed = 'API middleware and HTTP kernel'): array
{
    configureFakeGitHubApp();
    $owner = User::factory()->create(['name' => 'Ada Lovelace']);
    $repository = Repository::factory()->create(['github_full_name' => 'o/r', 'default_branch' => 'main', 'default_responsible_user_id' => $owner->id]);
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: {$mode}\n", 'risk-profile.yml' => riskProfileYaml()]);
    $task = YakTask::factory()->create([
        'repo' => $repository->slug, 'source' => 'slack', 'mode' => TaskMode::Fix,
        'started_by_user_id' => null, 'slack_user_id' => null, 'description' => 'change the middleware',
        ...$taskAttributes,
    ]);

    $guesser = Mockery::mock(RiskAreaGuesser::class);
    $guesser->shouldReceive('guess')->andReturn($guessed === null ? [] : [$guessed])->byDefault();
    app()->instance(RiskAreaGuesser::class, $guesser);

    return [$task, $repository, $guesser];
}

function fakeWriteAccess(string $result): void
{
    $access = Mockery::mock(GitHubWriteAccess::class);
    $access->shouldReceive('check')->andReturn($result);
    app()->instance(GitHubWriteAccess::class, $access);
}

it('warns an unlinked Slack requester about a held guessed area', function () {
    [$task] = earlyWarningFixture();

    expect(app(EarlyWarning::class)->evaluate($task))->toBe([
        'areas' => [['name' => 'API middleware and HTTP kernel', 'risk' => 'high']],
        'repoName' => 'o/r',
        'fallbackName' => 'Ada',
    ]);
});

it('warns a requester who only has read access', function () {
    $user = User::factory()->create();
    [$task] = earlyWarningFixture(['started_by_user_id' => $user->id]);
    fakeWriteAccess('read');

    expect(app(EarlyWarning::class)->evaluate($task))->not->toBeNull();
});

it('stays quiet when the mode is not enforce', function () {
    [$task] = earlyWarningFixture(mode: 'off');

    expect(app(EarlyWarning::class)->evaluate($task))->toBeNull();
});

it('stays quiet for exempt modes and sources', function (array $attributes) {
    [$task] = earlyWarningFixture($attributes);

    expect(app(EarlyWarning::class)->evaluate($task))->toBeNull();
})->with([
    'setup' => [['mode' => TaskMode::Setup]],
    'review' => [['mode' => TaskMode::Review]],
    'research' => [['mode' => TaskMode::Research]],
    'sentry' => [['source' => 'sentry']],
    'flaky-test' => [['source' => 'flaky-test']],
]);

it('stays quiet for a requester with write access or an unknown access result, without guessing', function (string $result) {
    $user = User::factory()->create();
    [$task, , $guesser] = earlyWarningFixture(['started_by_user_id' => $user->id]);
    fakeWriteAccess($result);
    $guesser->shouldNotReceive('guess');

    expect(app(EarlyWarning::class)->evaluate($task))->toBeNull();
})->with(['write', 'error']);

it('stays quiet without a risk profile', function () {
    [$task] = earlyWarningFixture();
    fakeYakFiles(['config.yml' => "version: 1\nco_owner_gate:\n  mode: enforce\n"]);

    expect(app(EarlyWarning::class)->evaluate($task))->toBeNull();
});

it('stays quiet when the guess names nothing held', function () {
    [$task] = earlyWarningFixture(guessed: null);

    expect(app(EarlyWarning::class)->evaluate($task))->toBeNull();
});

it('never warns on a follow-up task', function () {
    [$root] = earlyWarningFixture();
    $followUp = YakTask::factory()->create(['repo' => $root->repo, 'parent_task_id' => $root->id, 'source' => 'slack', 'mode' => TaskMode::Fix]);

    expect(app(EarlyWarning::class)->evaluate($followUp))->toBeNull();
});

it('stays quiet when the repository does not resolve', function () {
    [$task] = earlyWarningFixture(['repo' => 'unknown']);

    expect(app(EarlyWarning::class)->evaluate($task))->toBeNull();
});

it('keeps the early warning json on the conversation root', function () {
    [$root] = earlyWarningFixture();
    $followUp = YakTask::factory()->create(['repo' => $root->repo, 'parent_task_id' => $root->id]);
    $coOwner = User::factory()->create();
    $state = app(EarlyWarningState::class);

    $state->setSlackMessage($followUp, 'C1', '1.2');
    expect($root->fresh()->early_warning)->toBeNull();

    $state->start($followUp, [['name' => 'Billing', 'risk' => 'critical']]);
    $state->setSlackMessage($followUp, 'C1', '1.2');
    expect($root->fresh()->early_warning)->toMatchArray(['state' => 'shown', 'slackChannel' => 'C1', 'slackTs' => '1.2']);

    $state->tagged($followUp, $coOwner);
    $fresh = $root->fresh();
    expect($fresh->early_warning['state'])->toBe('tagged')
        ->and($fresh->early_warning['taggedAt'])->not->toBeNull()
        ->and($fresh->tagged_co_owner_user_id)->toBe($coOwner->id)
        ->and($fresh->taggedCoOwner->is($coOwner))->toBeTrue();

    $state->declined($followUp);
    expect($root->fresh()->early_warning['state'])->toBe('declined');
});

it('identifies the requester of a root', function () {
    config()->set('yak.channels.slack.bot_token', '');
    $starter = User::factory()->create();
    $other = User::factory()->create(['slack_user_id' => 'U9']);
    $started = YakTask::factory()->create(['started_by_user_id' => $starter->id]);
    $slack = YakTask::factory()->create(['started_by_user_id' => null, 'slack_user_id' => 'U1']);
    $requesters = app(TaskRequester::class);

    expect($requesters->isRequester($started, $starter, null))->toBeTrue()
        ->and($requesters->isRequester($started, $other, 'U1'))->toBeFalse()
        ->and($requesters->isRequester($slack, null, 'U1'))->toBeTrue()
        ->and($requesters->isRequester($slack, $other, 'U9'))->toBeFalse();
});
