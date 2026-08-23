<?php

use App\Channels\ChannelRegistry;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

test('dashboard-sourced task with an open PR does not post to GitHub', function () {
    Http::fake(); // any github API call would be recorded
    config()->set('yak.channels.github.installation_id', 4242);

    GitHubInstallationToken::factory()->create([
        'installation_id' => 4242,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/9',
    ]);

    (new SendNotificationJob($task, NotificationType::Progress, 'pushed, waiting for CI'))->handle(app(ChannelRegistry::class));

    Http::assertNothingSent();
});

test('non-dashboard source with PR still falls back to GitHub', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1])]);

    config()->set('yak.channels.github.installation_id', 4242);

    GitHubInstallationToken::factory()->create([
        'installation_id' => 4242,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    $task = YakTask::factory()->success()->create([
        'source' => 'sentry',
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/9',
    ]);

    (new SendNotificationJob($task, NotificationType::Progress, 'pushed, waiting for CI'))->handle(app(ChannelRegistry::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'acme/web/issues/9/comments'));
});
