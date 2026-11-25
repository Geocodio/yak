<?php

use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\ReviewFeedbackFormatter;
use Illuminate\Support\Facades\Queue;

function reviewFeedbackFixture(): array
{
    return app(ReviewFeedbackFormatter::class)->toReviewFeedback('commented', '', [[
        'id' => 4683388661,
        'body' => 'Please **fix** this',
        'author' => 'alice',
        'file' => 'app/Billing.php',
        'line' => 205,
        'diff_hunk' => "@@ -195,7 +200,9 @@ handle\n }\n }\n \n-old\n+// note\n+new",
    ]], 'alice', 'https://github.com/acme/web/pull/9', 500);
}

function reviewRoot(): YakTask
{
    return YakTask::factory()->success()->create(['source' => 'github', 'pr_url' => 'https://github.com/acme/web/pull/9', 'pr_number' => 9, 'branch_name' => 'yak/x']);
}

it('shows a review follow-up as a review instead of its prompt text', function () {
    Queue::fake();
    $child = app(FollowUpTaskFactory::class)->create(reviewRoot(), 'instructions with [c:4683388661]', 'github', authorName: 'alice', reviewFeedback: reviewFeedbackFixture());
    $run = TaskRun::factory()->create(['yak_task_id' => $child->id, 'thread_id' => $child->thread_id]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'text' => null]);

    $message = collect(ThreadMessagesData::for(Thread::query()->findOrFail($child->thread_id)))->last();

    expect($message['via'])->toBe(['key' => 'github', 'label' => 'GitHub', 'reference' => '#9', 'url' => 'https://github.com/acme/web/pull/9#pullrequestreview-500'])
        ->and($message['review']['state'])->toBe('commented')
        ->and($message['review']['avatarUrl'])->toBe('https://github.com/alice.png?size=48')
        ->and($message['review']['comments'][0])->toMatchArray([
            'file' => 'app/Billing.php',
            'line' => 205,
            'diff' => [' ', '-old', '+// note', '+new'],
            'url' => 'https://github.com/acme/web/pull/9#discussion_r4683388661',
        ])
        ->and($message['review']['comments'][0]['bodyHtml'])->toContain('<strong>fix</strong>');
});

it('keeps the review on a steered message after its queued row is drained', function () {
    $root = reviewRoot();
    $steer = PendingSteeringMessage::queueFor($root, 'instructions', 'github_review', reviewerLogin: 'alice', authorName: 'alice', reviewFeedback: reviewFeedbackFixture());
    $run = TaskRun::factory()->create(['yak_task_id' => $root->id, 'thread_id' => $root->thread_id]);

    PendingSteeringMessage::drain($root, collect([$steer]), $run);

    $message = collect(ThreadMessagesData::for(Thread::query()->findOrFail($root->thread_id)))->firstWhere('authorName', 'alice');

    expect($message['review']['comments'][0]['file'])->toBe('app/Billing.php')
        ->and($message['isReply'])->toBeTrue();
});

it('shows a plain message with no review', function () {
    $root = reviewRoot();
    $run = TaskRun::factory()->create(['yak_task_id' => $root->id, 'thread_id' => $root->thread_id]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'text' => 'hello']);

    expect(ThreadMessagesData::for(Thread::query()->findOrFail($root->thread_id))[0]['review'])->toBeNull();
});
