<?php

use App\Channels\GitHub\AppService;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;

it('injectGitCredentials() gives the sandbox gh a read-only token through a PATH wrapper', function () {
    config()->set('yak.channels.github.installation_id', 42);
    $this->mock(AppService::class, function ($mock) {
        $mock->shouldReceive('getInstallationToken')->andReturn('ghs_write');
        $mock->shouldReceive('getReadOnlyInstallationToken')->with(42)->andReturn('ghs_read');
    });
    Process::fake();

    (new IncusSandboxManager)->injectGitCredentials('task-1');

    Process::assertRan(fn ($process) => str_contains($process->command, '.config/yak/gh-token') && $process->input === 'ghs_read');
    Process::assertRan(fn ($process) => str_contains($process->command, '/usr/local/bin/gh') && str_contains((string) $process->input, 'exec /usr/bin/gh'));
});

it('injectGitCredentials() still sets git credentials when the read-only token fails', function () {
    config()->set('yak.channels.github.installation_id', 42);
    $this->mock(AppService::class, function ($mock) {
        $mock->shouldReceive('getInstallationToken')->andReturn('ghs_write');
        $mock->shouldReceive('getReadOnlyInstallationToken')->andThrow(new RuntimeException('422'));
    });
    Process::fake();

    (new IncusSandboxManager)->injectGitCredentials('task-1');

    Process::assertRan(fn ($process) => str_contains($process->command, 'credential.https://github.com.helper'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, '/usr/local/bin/gh'));
});
