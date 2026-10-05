<?php

use App\Channels\GitHub\AppService;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->transcriptDir = sys_get_temp_dir() . '/yak-transcripts-' . uniqid();
    config()->set('yak.sandbox.session_transcript_path', $this->transcriptDir);
    config()->set('yak.sandbox.workspace_path', '/workspace');
});

afterEach(function () {
    if (is_dir($this->transcriptDir)) {
        array_map('unlink', glob($this->transcriptDir . '/*') ?: []);
        rmdir($this->transcriptDir);
    }
});

it('pullSessionTranscript() copies the session transcript out of the sandbox to the host', function () {
    Process::fake([
        '*find /home/yak/.claude/projects*' => Process::result("/home/yak/.claude/projects/-workspace/sess-abc.jsonl\n"),
        '*' => Process::result(''),
    ]);

    (new IncusSandboxManager)->pullSessionTranscript('task-1', 'sess-abc');

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus file pull')
        && str_contains($process->command, 'task-1/home/yak/.claude/projects/-workspace/sess-abc.jsonl')
        && str_contains($process->command, $this->transcriptDir . '/sess-abc.jsonl'));
});

it('pullSessionTranscript() no-ops when the sandbox has no transcript for the session', function () {
    Process::fake([
        '*find /home/yak/.claude/projects*' => Process::result(''),
        '*' => Process::result(''),
    ]);

    (new IncusSandboxManager)->pullSessionTranscript('task-1', 'sess-missing');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'incus file pull'));
});

it('pullSessionTranscript() no-ops on a null or empty session id', function () {
    Process::fake(['*' => Process::result('')]);

    $manager = new IncusSandboxManager;
    $manager->pullSessionTranscript('task-1', null);
    $manager->pullSessionTranscript('task-1', '');

    Process::assertNothingRan();
});

it('pushSessionTranscript() pushes a persisted transcript into the sandbox projects dir', function () {
    mkdir($this->transcriptDir, 0755, true);
    file_put_contents($this->transcriptDir . '/sess-abc.jsonl', '{"type":"user"}');

    Process::fake(['*' => Process::result('')]);

    $pushed = (new IncusSandboxManager)->pushSessionTranscript('task-2', 'sess-abc');

    expect($pushed)->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'mkdir -p')
        && str_contains($process->command, '/home/yak/.claude/projects/-workspace'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'incus file push')
        && str_contains($process->command, $this->transcriptDir . '/sess-abc.jsonl')
        && str_contains($process->command, 'task-2/home/yak/.claude/projects/-workspace/sess-abc.jsonl'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'chown -R yak:yak')
        && str_contains($process->command, '/home/yak/.claude/projects'));
});

it('pushSessionTranscript() returns false when no transcript was persisted for the session', function () {
    Process::fake(['*' => Process::result('')]);

    $pushed = (new IncusSandboxManager)->pushSessionTranscript('task-2', 'sess-unknown');

    expect($pushed)->toBeFalse();
    Process::assertNothingRan();
});

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
