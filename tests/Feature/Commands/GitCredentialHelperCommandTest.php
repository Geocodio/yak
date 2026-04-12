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
