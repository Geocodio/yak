<?php

use App\ClaudeCli;

it('runs the cli from the shared config home so project-scoped config of the app itself is ignored', function () {
    $command = app(ClaudeCli::class)->interactiveCommand('mcp list');

    expect($command)
        ->toStartWith("cd '/home/yak' 2>/dev/null; env HOME='/home/yak' CLAUDE_CONFIG_DIR='/home/yak/.claude'")
        ->toEndWith('claude mcp list');
});
