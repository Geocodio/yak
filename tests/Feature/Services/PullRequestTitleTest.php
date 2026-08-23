<?php

use App\Ai\Agents\PullRequestTitleWriter;
use App\Models\YakTask;
use App\Services\PullRequestTitle;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['yak.pr_title_writer.enabled' => true]);
});

it('returns the generated title stripped of quotes and whitespace', function () {
    PullRequestTitleWriter::fake(['  "Reject duplicate spam blocklist entries in Nova"  ']);

    $task = YakTask::factory()->create(['description' => 'Looks like this is the error: <https://sentry.io/...>']);

    $title = app(PullRequestTitle::class)->generate($task);

    expect($title)->toBe('Reject duplicate spam blocklist entries in Nova');
});

it('truncates titles longer than the maximum length', function () {
    PullRequestTitleWriter::fake([str_repeat('a', 120)]);

    $task = YakTask::factory()->create();

    $title = app(PullRequestTitle::class)->generate($task);

    expect(mb_strlen($title))->toBe(PullRequestTitle::MAX_LENGTH)
        ->and($title)->toEndWith('...');
});

it('returns null when the model responds with an empty string', function () {
    PullRequestTitleWriter::fake(['   ']);

    $task = YakTask::factory()->create();

    expect(app(PullRequestTitle::class)->generate($task))->toBeNull();
});

it('returns null when the model responds with multiple lines', function () {
    PullRequestTitleWriter::fake(["Here is your title:\nFix the bug"]);

    $task = YakTask::factory()->create();

    expect(app(PullRequestTitle::class)->generate($task))->toBeNull();
});

it('returns null and logs a warning when the agent throws', function () {
    Log::shouldReceive('channel')
        ->with('yak')
        ->once()
        ->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->with('PR title generation failed', Mockery::on(fn ($context) => isset($context['error'])));

    PullRequestTitleWriter::fake(function () {
        throw new RuntimeException('provider down');
    });

    $task = YakTask::factory()->create();

    expect(app(PullRequestTitle::class)->generate($task))->toBeNull();
});

it('returns null without calling the agent when disabled', function () {
    config(['yak.pr_title_writer.enabled' => false]);
    PullRequestTitleWriter::fake()->preventStrayPrompts();

    $task = YakTask::factory()->create();

    expect(app(PullRequestTitle::class)->generate($task))->toBeNull();
});
