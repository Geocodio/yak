<?php

use App\Enums\TaskMode;
use App\Models\YakTask;
use App\YakPromptBuilder;

it('renders the prior-findings section only when priorFindings is non-empty', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Review]);

    $contextWithout = [
        'prNumber' => 50, 'prTitle' => 't', 'prBody' => 'b', 'prAuthor' => 'm',
        'baseBranch' => 'main', 'headBranch' => 'feat/x', 'diffSummary' => '',
        'reviewScope' => 'incremental', 'changedFiles' => ['app/Foo.php'],
        'repoAgentInstructions' => '', 'pathExcludes' => [], 'linearTicket' => null,
        'priorFindings' => [],
    ];

    $promptWithout = YakPromptBuilder::taskPrompt($task, $contextWithout);
    expect($promptWithout)->not->toContain('## Prior findings');

    $contextWith = array_merge($contextWithout, [
        'priorFindings' => [[
            'comment_id' => 42, 'file' => 'app/Foo.php', 'line' => 10,
            'severity' => 'must_fix', 'category' => 'Performance',
            'body' => 'Bug here.', 'file_changed_in_this_push' => true,
        ]],
    ]);

    $promptWith = YakPromptBuilder::taskPrompt($task, $contextWith);
    expect($promptWith)->toContain('## Prior findings (unresolved threads)')
        ->and($promptWith)->toContain('id=42')
        ->and($promptWith)->toContain('severity=must_fix')
        ->and($promptWith)->toContain('File changed in this push: yes')
        ->and($promptWith)->toContain('Bug here.');
});
