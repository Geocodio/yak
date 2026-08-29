<?php

use App\Channels\GitHub\AppService;
use App\ClaudeCli;
use App\Exceptions\ClaudeCliException;
use App\Services\SkillManager;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Git credentials for private marketplaces
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('yak.skills_github_token', null);
    config()->set('yak.channels.github.installation_id', 0);
});

it('runs the claude CLI without git credentials when none are configured', function () {
    Process::fake();

    app(ClaudeCli::class)->exec('plugins marketplace list');

    Process::assertRan(function ($process) {
        return ! str_contains($process->command, 'GIT_CONFIG_COUNT')
            && str_contains($process->command, 'claude plugins marketplace list');
    });
});

it('passes the configured override token as a throwaway git credential helper', function () {
    Process::fake();

    config()->set('yak.skills_github_token', 'pat-token');

    app(ClaudeCli::class)->exec('plugins marketplace list');

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'GIT_CONFIG_COUNT=1')
            && str_contains($process->command, 'credential.https://github.com.helper')
            && str_contains($process->command, 'username=x-access-token')
            && str_contains($process->command, 'password=pat-token');
    });
});

it('falls back to the GitHub App installation token', function () {
    Process::fake();

    config()->set('yak.channels.github.installation_id', 4242);

    $appService = Mockery::mock(AppService::class);
    $appService->shouldReceive('getInstallationToken')->with(4242)->once()->andReturn('ghs-token');
    app()->instance(AppService::class, $appService);

    app(ClaudeCli::class)->exec('plugins marketplace list');

    Process::assertRan(fn ($process) => str_contains($process->command, 'password=ghs-token'));
});

it('prefers the override token over the GitHub App installation token', function () {
    Process::fake();

    config()->set('yak.skills_github_token', 'pat-token');
    config()->set('yak.channels.github.installation_id', 4242);

    $appService = Mockery::mock(AppService::class);
    $appService->shouldNotReceive('getInstallationToken');
    app()->instance(AppService::class, $appService);

    app(ClaudeCli::class)->exec('plugins marketplace list');

    Process::assertRan(fn ($process) => str_contains($process->command, 'password=pat-token'));
});

it('still runs the command when minting an installation token fails', function () {
    Process::fake();

    config()->set('yak.channels.github.installation_id', 4242);

    $appService = Mockery::mock(AppService::class);
    $appService->shouldReceive('getInstallationToken')->andThrow(new RuntimeException('GitHub down'));
    app()->instance(AppService::class, $appService);

    app(ClaudeCli::class)->exec('plugins marketplace list');

    Process::assertRan(function ($process) {
        return ! str_contains($process->command, 'GIT_CONFIG_COUNT')
            && str_contains($process->command, 'claude plugins marketplace list');
    });
});

/*
|--------------------------------------------------------------------------
| SkillManager wiring
|--------------------------------------------------------------------------
*/

it('adds a private marketplace with credentials attached', function () {
    Process::fake();

    config()->set('yak.skills_github_token', 'pat-token');

    app(SkillManager::class)->addMarketplace('acme/private-skills');

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'password=pat-token')
            && str_contains($process->command, "plugins marketplace add 'acme/private-skills'");
    });
});

it('surfaces the CLI error when adding a marketplace fails', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'repository not found', exitCode: 1),
    ]);

    expect(fn () => app(SkillManager::class)->addMarketplace('acme/private-skills'))
        ->toThrow(ClaudeCliException::class, 'repository not found');
});
