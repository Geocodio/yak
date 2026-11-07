<?php

use App\Jobs\SendPreviewRepairMessagesJob;
use App\Models\PreviewRepair;
use App\Models\Repository;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendPreviewRepairMessagesJob::class]);
});

function openRepair(array $attributes): PreviewRepair
{
    $repository = Repository::factory()->create();
    $previewRepair = PreviewRepair::factory()->for($repository)->recipeWithOpenPullRequest()->create($attributes);
    $previewRepair->task->update(['pr_url' => 'https://github.com/acme/site/pull/101']);

    return $previewRepair;
}

it('reminds after three days of new failures', function () {
    $previewRepair = openRepair(['last_notified_at' => now()->subDays(3)->subMinute(), 'last_failed_at' => now()->subHour()]);

    $this->artisan('yak:preview-repair:sweep')->assertSuccessful();

    Queue::assertPushed(SendPreviewRepairMessagesJob::class, fn (SendPreviewRepairMessagesJob $job) => $job->previewRepairId === $previewRepair->id && $job->event === 'reminder');
    expect($previewRepair->fresh()->last_notified_at->isToday())->toBeTrue();
});

it('does not remind without new failures', function () {
    openRepair(['last_notified_at' => now()->subDays(4), 'last_failed_at' => now()->subDays(5)]);

    $this->artisan('yak:preview-repair:sweep')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('does not remind before three days', function () {
    openRepair(['last_notified_at' => now()->subDays(2), 'last_failed_at' => now()->subHour()]);

    $this->artisan('yak:preview-repair:sweep')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('does not remind after the pull request merged', function () {
    $previewRepair = openRepair(['last_notified_at' => now()->subDays(4), 'last_failed_at' => now()->subHour()]);
    $previewRepair->task->update(['pr_merged_at' => now()]);

    $this->artisan('yak:preview-repair:sweep')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('claims each repair once', function () {
    openRepair(['last_notified_at' => now()->subDays(4), 'last_failed_at' => now()->subHour()]);

    $this->artisan('yak:preview-repair:sweep')->assertSuccessful();
    $this->artisan('yak:preview-repair:sweep')->assertSuccessful();

    Queue::assertPushed(SendPreviewRepairMessagesJob::class, 1);
});
