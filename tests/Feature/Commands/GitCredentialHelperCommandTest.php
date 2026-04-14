<?php

use App\Services\GitHubAppService;

test('git credential helper outputs token for github.com requests', function () {
    $mockService = Mockery::mock(GitHubAppService::class);
    $mockService->shouldReceive('getInstallationToken')
        ->with(12345)
        ->once()
        ->andReturn('ghs_test_token_abc123');

    $this->app->instance(GitHubAppService::class, $mockService);

    config(['yak.channels.github.installation_id' => 12345]);

    $this->artisan('yak:git-credential', ['--stdin' => "protocol=https\nhost=github.com\n\n"])
        ->assertSuccessful();
});

test('git credential helper ignores non-github requests', function () {
    config(['yak.channels.github.installation_id' => 12345]);

    $this->artisan('yak:git-credential', ['--stdin' => "protocol=https\nhost=gitlab.com\n\n"])
        ->assertSuccessful();
});

test('git credential helper fails when installation id is not configured', function () {
    config(['yak.channels.github.installation_id' => 0]);

    $this->artisan('yak:git-credential', ['--stdin' => "protocol=https\nhost=github.com\n\n"])
        ->assertFailed();
});

test('git credential helper accepts the action arg git passes (get/store/erase)', function () {
    $mockService = Mockery::mock(GitHubAppService::class);
    $mockService->shouldReceive('getInstallationToken')
        ->with(12345)
        ->once()
        ->andReturn('ghs_test_token');

    $this->app->instance(GitHubAppService::class, $mockService);
    config(['yak.channels.github.installation_id' => 12345]);

    $this->artisan('yak:git-credential', [
        'action' => 'get',
        '--stdin' => "protocol=https\nhost=github.com\n\n",
    ])->assertSuccessful();
});

test('git credential helper no-ops on store/erase actions', function () {
    config(['yak.channels.github.installation_id' => 12345]);

    $this->artisan('yak:git-credential', [
        'action' => 'store',
        '--stdin' => "protocol=https\nhost=github.com\n\n",
    ])->assertSuccessful();

    $this->artisan('yak:git-credential', [
        'action' => 'erase',
        '--stdin' => "protocol=https\nhost=github.com\n\n",
    ])->assertSuccessful();
});
