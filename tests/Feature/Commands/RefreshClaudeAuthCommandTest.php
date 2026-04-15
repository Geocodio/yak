<?php

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('yak.sandbox.claude_config_source', '/home/yak/.claude');
});

it('succeeds when claude returns successfully', function () {
    Process::fake([
        '*claude --model claude-haiku-4-5 -p *' => Process::result(output: 'ok', exitCode: 0),
    ]);

    $this->artisan('yak:refresh-claude-auth')->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, "HOME='/home/yak'")
        && str_contains($process->command, "CLAUDE_CONFIG_DIR='/home/yak/.claude'")
        && str_contains($process->command, '--model claude-haiku-4-5'));
});

it('fails when claude exits non-zero', function () {
    Process::fake([
        '*claude --model claude-haiku-4-5 -p *' => Process::result(
            output: '',
            errorOutput: 'Not logged in',
            exitCode: 1,
        ),
    ]);

    $this->artisan('yak:refresh-claude-auth')->assertFailed();
});
