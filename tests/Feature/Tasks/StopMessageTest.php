<?php

use App\DataTransferObjects\TaskStop;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Tasks\StopMessage;

function stopFor(StopKind $kind): TaskStop
{
    return new TaskStop($kind, 'Summary.', 'detail', $kind === StopKind::NeedsYou ? 'your answer' : null);
}

dataset('next steps', function () {
    $retrying = 'Yak picks it back up on its own. Nothing needed from you.';

    return [
        'slack stopped' => ['slack', StopKind::YakStopped, 'Reply here and Yak will try again. Details: %url%', 'Press Retry on the thread, or write a note there, and Yak will try again: %url%'],
        'slack needs you' => ['slack', StopKind::NeedsYou, 'Reply here with your answer and Yak will try again.', 'Write your answer on the thread and Yak will try again: %url%'],
        'linear stopped' => ['linear', StopKind::YakStopped, 'Reply here and Yak will try again. Details: %url%', 'Reply in the Linear issue to try again: https://linear.app/acme/issue/ENG-1'],
        'linear needs you' => ['linear', StopKind::NeedsYou, 'Reply here with your answer and Yak will try again.', 'Reply in the Linear issue with your answer to try again: https://linear.app/acme/issue/ENG-1'],
        'dashboard stopped' => ['dashboard', StopKind::YakStopped, 'Press Retry on the thread, or write a note there, and Yak will try again: %url%', 'Press Retry on the thread, or write a note there, and Yak will try again: %url%'],
        'dashboard needs you' => ['dashboard', StopKind::NeedsYou, 'Write your answer on the thread and Yak will try again: %url%', 'Write your answer on the thread and Yak will try again: %url%'],
        'github stopped' => ['github', StopKind::YakStopped, 'Press Retry on the thread, or write a note there, and Yak will try again: %url%', 'Press Retry on the thread, or write a note there, and Yak will try again: %url%'],
        'slack retrying' => ['slack', StopKind::YakRetrying, $retrying, $retrying],
        'email retrying' => ['email', StopKind::YakRetrying, $retrying, $retrying],
    ];
});

test('each destination gets the next step that fits it', function (string $source, StopKind $kind, string $sourceStep, string $directStep) {
    $task = YakTask::factory()->create(['source' => $source, 'external_url' => 'https://linear.app/acme/issue/ENG-1']);
    $url = route('threads.show', Thread::query()->find($task->thread_id));
    $stop = stopFor($kind);

    expect(StopMessage::forSourceChannel($task, $stop))->toBe("Summary.\n\n" . str_replace('%url%', $url, $sourceStep))
        ->and(StopMessage::forDirectMessage($task, $stop))->toBe("Summary.\n\n" . str_replace('%url%', $url, $directStep));
})->with('next steps');

test('a Linear direct message never says reply here', function () {
    $task = YakTask::factory()->create(['source' => 'linear', 'external_url' => null]);

    expect(StopMessage::forDirectMessage($task, stopFor(StopKind::YakStopped)))
        ->not->toContain('Reply here')
        ->toContain('Press Retry on the thread, or write a note there');
});

test('only linear and slack accept replies', function () {
    expect(StopMessage::acceptsReplies(YakTask::factory()->make(['source' => 'slack'])))->toBeTrue()
        ->and(StopMessage::acceptsReplies(YakTask::factory()->make(['source' => 'linear'])))->toBeTrue()
        ->and(StopMessage::acceptsReplies(YakTask::factory()->make(['source' => 'email'])))->toBeFalse();
});

test('a size limit reads naturally on every destination', function () {
    $stop = TaskStop::sizeLimit('detail');
    $slack = YakTask::factory()->create(['source' => 'slack']);
    $email = YakTask::factory()->create(['source' => 'email']);

    expect(StopMessage::forSourceChannel($slack, $stop))->toEndWith('Reply here to let Yak continue from where it stopped, or with a narrower scope.')
        ->and(StopMessage::forSourceChannel($email, $stop))->toContain('Write a narrower scope on the thread and Yak will try again: ');
});

test('a review says review and a setup run says run', function () {
    $review = YakTask::factory()->create(['source' => 'github', 'mode' => TaskMode::Review, 'repo' => 'acme/widgets', 'pr_number' => 7]);
    $repository = Repository::factory()->create(['slug' => 'acme/widgets']);
    $setup = YakTask::factory()->create(['source' => 'dashboard', 'mode' => TaskMode::Setup, 'repo' => 'acme/widgets']);

    expect(StopMessage::forDirectMessage($review, stopFor(StopKind::YakStopped)))
        ->toBe("Summary.\n\nPress Retry on the review, or write a note there, and Yak will try again: " . route('pr-reviews.for-pr', ['acme/widgets', 7]) . '#run-' . $review->public_id)
        ->and(StopMessage::forDirectMessage($setup, stopFor(StopKind::YakStopped)))
        ->toBe("Summary.\n\nPress Retry on the run, or write a note there, and Yak will try again: " . route('repos.runs.show', [$repository, $setup]));
});

test('a task with no page gets the step without a link', function () {
    $task = YakTask::factory()->create(['source' => 'dashboard', 'thread_id' => null]);

    expect(StopMessage::forDirectMessage($task, stopFor(StopKind::YakStopped)))
        ->toBe("Summary.\n\nPress Retry on the thread, or write a note there, and Yak will try again.");
});
