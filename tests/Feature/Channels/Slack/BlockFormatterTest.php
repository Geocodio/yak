<?php

use App\Channels\Slack\BlockFormatter;
use App\Enums\NotificationType;
use App\Models\YakTask;

it('builds a header section, context chips, and a View task button for acknowledgments', function () {
    $task = YakTask::factory()->create([
        'repo' => 'acme/web',
    ]);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Acknowledgment,
        'Chewing through this now! 🐃',
        'https://yak.example.com/tasks/' . $task->id,
    );

    expect($blocks[0])
        ->toMatchArray([
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => 'Chewing through this now! 🐃'],
        ]);

    expect($blocks[1]['type'])->toBe('context');
    expect($blocks[1]['elements'][0]['text'])
        ->toContain('*Repo:* `acme/web`')
        ->toContain('*Mode:*')
        ->toContain('*Task:* #' . $task->id);

    expect($blocks[2]['type'])->toBe('actions');
    expect($blocks[2]['elements'][0])->toMatchArray([
        'type' => 'button',
        'action_id' => 'yak_view_task',
        'text' => ['type' => 'plain_text', 'text' => 'View task'],
        'url' => 'https://yak.example.com/tasks/' . $task->id,
        'style' => 'primary',
    ]);
});

it('omits context chips for Progress notifications to reduce noise', function () {
    $task = YakTask::factory()->create(['repo' => 'acme/web']);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Progress,
        'Still working… ⏳',
        'https://yak.example.com/tasks/' . $task->id,
    );

    $types = array_column($blocks, 'type');
    expect($types)->toBe(['section', 'actions']);
});

it('omits context chips for Clarification because the message already carries the options', function () {
    $task = YakTask::factory()->create(['repo' => 'acme/web']);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Clarification,
        'Need input: option a or option b? ❓',
        'https://yak.example.com/tasks/' . $task->id,
    );

    $types = array_column($blocks, 'type');
    expect($types)->toBe(['section', 'actions']);
});

it('adds a View PR button when the task has a PR URL', function () {
    $task = YakTask::factory()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/42',
    ]);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Result,
        'PR opened — hooves up! ✅',
        'https://yak.example.com/tasks/' . $task->id,
    );

    $actionBlock = collect($blocks)->firstWhere('type', 'actions');
    $urls = collect($actionBlock['elements'])->pluck('url')->all();

    expect($urls)->toContain('https://yak.example.com/tasks/' . $task->id);
    expect($urls)->toContain('https://github.com/acme/web/pull/42');

    // Slack's renderer warns ("Slack cannot handle payload") on URL
    // buttons that lack an action_id, so every button needs one even
    // though the interactive webhook never sees a callback for them.
    $actionIds = collect($actionBlock['elements'])->pluck('action_id')->all();
    expect($actionIds)->toContain('yak_view_task');
    expect($actionIds)->toContain('yak_view_pr');
});

it('uses primary button style for Acknowledgment and Result only', function () {
    $task = YakTask::factory()->create();

    foreach (NotificationType::cases() as $type) {
        $blocks = BlockFormatter::blocks(
            $task,
            $type,
            'msg',
            'https://yak.example.com/tasks/' . $task->id,
        );

        $viewTask = collect($blocks)->firstWhere('type', 'actions')['elements'][0];

        $shouldBePrimary = in_array($type, [NotificationType::Acknowledgment, NotificationType::Result], true);

        if ($shouldBePrimary) {
            expect($viewTask['style'] ?? null)->toBe('primary');
        } else {
            expect($viewTask)->not->toHaveKey('style');
        }
    }
});

it('converts markdown bold and links to Slack mrkdwn', function () {
    expect(BlockFormatter::mrkdwn('**bold** and [link](https://example.com)'))
        ->toBe('*bold* and <https://example.com|link>');
});

it('produces a fallback text suitable for Slack notification previews', function () {
    expect(BlockFormatter::fallbackText('**Done!** [PR](https://github.com/a/b/pull/1)'))
        ->toBe('*Done!* <https://github.com/a/b/pull/1|PR>');
});

it('renders one button per clarification option', function () {
    $task = YakTask::factory()->create([
        'clarification_options' => ['acme/api', 'acme/web', 'acme/worker'],
    ]);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Clarification,
        'Which repo should I work in?',
        'https://yak.example.com/tasks/' . $task->id,
    );

    $optionActions = collect($blocks)->firstWhere('block_id', 'yak_clarify_options');

    expect($optionActions)->not->toBeNull();
    expect($optionActions['elements'])->toHaveCount(3);
    expect($optionActions['elements'][0])->toMatchArray([
        'type' => 'button',
        'action_id' => 'yak_clarify_0',
        'text' => ['type' => 'plain_text', 'text' => 'acme/api'],
        'value' => $task->id . '|acme/api',
    ]);
});

it('skips clarification option buttons when the task has no options', function () {
    $task = YakTask::factory()->create(['clarification_options' => null]);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Clarification,
        'Hmm?',
        'https://yak.example.com/tasks/' . $task->id,
    );

    expect(collect($blocks)->firstWhere('block_id', 'yak_clarify_options'))->toBeNull();
});

it('skips clarification buttons on non-Clarification notification types', function () {
    $task = YakTask::factory()->create([
        'clarification_options' => ['a', 'b'],
    ]);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Progress,
        'working',
        'https://yak.example.com/tasks/' . $task->id,
    );

    expect(collect($blocks)->firstWhere('block_id', 'yak_clarify_options'))->toBeNull();
});

it('truncates long option labels to fit Slack\'s 75-char button limit', function () {
    $long = str_repeat('x', 100);
    $task = YakTask::factory()->create(['clarification_options' => [$long]]);

    $blocks = BlockFormatter::blocks(
        $task,
        NotificationType::Clarification,
        'Pick one',
        'https://yak.example.com/tasks/' . $task->id,
    );

    $button = collect($blocks)->firstWhere('block_id', 'yak_clarify_options')['elements'][0];
    expect(mb_strlen($button['text']['text']))->toBeLessThanOrEqual(75);

    // But the value should still carry the full option so the reply
    // job gets the unambiguous text.
    expect($button['value'])->toBe($task->id . '|' . $long);
});
