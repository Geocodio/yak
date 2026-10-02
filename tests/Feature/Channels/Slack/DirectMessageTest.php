<?php

use App\Channels\Slack\NotificationDriver as SlackNotificationDriver;
use App\Enums\NotificationType;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
});

test('a direct message looks up the Slack user by email, stores the id and posts to that user', function () {
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U_JANE']]),
        'slack.com/*' => Http::response(['ok' => true]),
    ]);
    $user = User::factory()->create(['email' => 'jane@example.com']);
    $task = YakTask::factory()->create([
        'source' => 'dashboard',
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/412',
    ]);

    (new SlackNotificationDriver)->sendDirect($user, $task, NotificationType::Result, 'All green.', 'PR ready: Fix null postal code');
    (new SlackNotificationDriver)->sendDirect($user, $task, NotificationType::Result, 'All green.', 'PR ready: Fix null postal code');

    expect($user->fresh()->slack_user_id)->toBe('U_JANE');
    Http::assertSentCount(3); // one lookup, two posts
    Http::assertSent(function ($request) use ($task): bool {
        if (! str_contains($request->url(), 'chat.postMessage')) {
            return false;
        }

        $blocks = json_encode($request['blocks'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $request['channel'] === 'U_JANE'
            && $request['text'] === 'PR ready: Fix null postal code'
            && ! isset($request['thread_ts'])
            && str_contains($blocks, '*PR ready: Fix null postal code*')
            && str_contains($blocks, "geocodio/api · Task #{$task->id} · from Dashboard")
            && str_contains($blocks, '"View PR"')
            && str_contains($blocks, '"View task"');
    });
});

test('no Slack account for the email means no message and a retry next time', function () {
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => false, 'error' => 'users_not_found']),
        'slack.com/*' => Http::response(['ok' => true]),
    ]);
    $user = User::factory()->create();
    $task = YakTask::factory()->create(['source' => 'dashboard']);

    (new SlackNotificationDriver)->sendDirect($user, $task, NotificationType::Error, 'Broke.', 'Failed: x');
    (new SlackNotificationDriver)->sendDirect($user, $task, NotificationType::Error, 'Broke.', 'Failed: x');

    expect($user->fresh()->slack_user_id)->toBeNull();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'chat.postMessage'));
    Http::assertSentCount(2);
});

test('a ready preview adds an Open preview button and a Linear question adds Answer in Linear', function () {
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
    $user = User::factory()->create(['slack_user_id' => 'U_KNOWN']);
    $repository = Repository::factory()->create(['slug' => 'geocodio/api']);
    BranchDeployment::factory()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'yak/eng-231',
        'status' => 'running',
        'hostname' => 'eng-231.geocodio.yak.build',
    ]);
    $task = YakTask::factory()->create([
        'source' => 'linear',
        'repo' => 'geocodio/api',
        'branch_name' => 'yak/eng-231',
        'external_id' => 'LINEAR-ENG-231',
        'external_url' => 'https://linear.app/geocodio/issue/ENG-231',
    ]);

    (new SlackNotificationDriver)->sendDirect($user, $task, NotificationType::Reminder, 'Still need a pick.', 'Still waiting for your answer: x');

    Http::assertSent(function ($request): bool {
        $blocks = json_encode($request['blocks'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return str_contains($blocks, '"Open preview"')
            && str_contains($blocks, 'https://eng-231.geocodio.yak.build')
            && str_contains($blocks, '"Answer in Linear"')
            && str_contains($blocks, 'https://linear.app/geocodio/issue/ENG-231')
            && str_contains($blocks, 'from Linear ENG-231');
    });
});
