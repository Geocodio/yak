<?php

use App\Enums\NotificationType;
use App\Models\YakTask;
use App\Support\SlackBlockFormatter;

it('builds a header section, context chips, and a View task button for acknowledgments', function () {
    $task = YakTask::factory()->create([
        'repo' => 'acme/web',
    ]);

    $blocks = SlackBlockFormatter::blocks(
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
        'text' => ['type' => 'plain_text', 'text' => 'View task'],
        'url' => 'https://yak.example.com/tasks/' . $task->id,
        'style' => 'primary',
    ]);
});

it('omits context chips for Progress notifications to reduce noise', function () {
    $task = YakTask::factory()->create(['repo' => 'acme/web']);

    $blocks = SlackBlockFormatter::blocks(
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

    $blocks = SlackBlockFormatter::blocks(
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

    $blocks = SlackBlockFormatter::blocks(
        $task,
        NotificationType::Result,
        'PR opened — hooves up! ✅',
        'https://yak.example.com/tasks/' . $task->id,
    );

    $actionBlock = collect($blocks)->firstWhere('type', 'actions');
    $urls = collect($actionBlock['elements'])->pluck('url')->all();

    expect($urls)->toContain('https://yak.example.com/tasks/' . $task->id);
    expect($urls)->toContain('https://github.com/acme/web/pull/42');
});

it('uses primary button style for Acknowledgment and Result only', function () {
    $task = YakTask::factory()->create();

    foreach (NotificationType::cases() as $type) {
        $blocks = SlackBlockFormatter::blocks(
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
    expect(SlackBlockFormatter::mrkdwn('**bold** and [link](https://example.com)'))
        ->toBe('*bold* and <https://example.com|link>');
});

it('produces a fallback text suitable for Slack notification previews', function () {
    expect(SlackBlockFormatter::fallbackText('**Done!** [PR](https://github.com/a/b/pull/1)'))
        ->toBe('*Done!* <https://github.com/a/b/pull/1|PR>');
});
