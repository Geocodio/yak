<?php

use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\TaskRequester;
use Illuminate\Support\Facades\Http;

it('uses the user who started a dashboard task', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create(['source' => 'manual', 'started_by_user_id' => $user->id]);

    expect(app(TaskRequester::class)->for($task)?->is($user))->toBeTrue();
});

it('resolves the Slack user of a Slack task', function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    Http::fake();
    $user = User::factory()->create(['slack_user_id' => 'U1']);
    $task = YakTask::factory()->create(['source' => 'slack', 'slack_user_id' => 'U1', 'started_by_user_id' => null]);

    expect(app(TaskRequester::class)->for($task)?->is($user))->toBeTrue();
});

it('maps a GitHub task by author login to a linked user', function () {
    $user = User::factory()->withGitHub(7, 'octo')->create();
    $task = YakTask::factory()->create(['source' => 'github', 'author_name' => 'octo', 'started_by_user_id' => null]);

    expect(app(TaskRequester::class)->for($task)?->is($user))->toBeTrue();
});

it('returns null for a GitHub task whose author is not linked', function () {
    $task = YakTask::factory()->create(['source' => 'github', 'author_name' => 'stranger', 'started_by_user_id' => null]);

    expect(app(TaskRequester::class)->for($task))->toBeNull();
});
