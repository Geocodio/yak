<?php

use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\PrReview;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

test('shadow approval is displayed as a comment with its risk assessment', function () {
    $this->actingAs(User::factory()->create());
    PrReview::factory()->create([
        'repo' => 'geocodio/api', 'pr_number' => 50, 'verdict' => 'Approve',
        'risk_assessment' => [
            'event' => 'COMMENT', 'candidate' => 'APPROVE', 'mode' => 'shadow',
            'risk_score' => 25, 'model_confidence' => 90, 'profile_version' => null,
            'scoring_version' => 1, 'reasons' => [], 'signals' => [],
            'observed' => ['ci_verified' => true], 'score_components' => [],
        ],
    ]);

    visit(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertSee('Model verdict: Approve')
        ->assertSee('GitHub review: Comment only')
        ->assertSee('Shadow mode. Policy recommendation: approve.')
        ->assertSee('Risk: 25/100')
        ->assertDontSee('GitHub review: Approved');
});

test('task detail page reflects status update without manual refresh', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->running()->create([
        'description' => 'Implementing search feature',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertSee('running')
        ->assertSee('Implementing search feature');

    $task->status = TaskStatus::Success;
    $task->result_summary = 'Search feature implemented successfully';
    $task->completed_at = now();
    $task->saveQuietly();

    // A running task polls every 5s (`TaskDetailData::build`'s `pollInterval`);
    // wait past that plus a margin for the request/render round trip.
    $page->wait(7)
        ->assertSee('success')
        ->assertSee('Search feature implemented successfully');
});

test('task detail shows running status with pulse indicator', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $task = YakTask::factory()->running()->create([
        'description' => 'Running task test',
    ]);

    $page = visit(route('tasks.show', $task));

    $page->assertSee('running')
        ->assertPresent('.animate-pulse');
});

test('a poll does not reload the open walkthrough video', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->running()->create();
    Artifact::factory()->videoCut()->create(['yak_task_id' => $task->id]);

    $page = visit(route('tasks.show', $task))
        ->click('@walkthrough-poster')
        ->assertPresent('[data-testid="walkthrough-player"] video');

    $sourceBeforePoll = $page->script('document.querySelector("[data-testid=walkthrough-player] video").getAttribute("src")');

    // A running task polls every 5s, and each poll signs the cut's URL afresh.
    $page->wait(7);

    expect($page->script('document.querySelector("[data-testid=walkthrough-player] video").getAttribute("src")'))
        ->toBe($sourceBeforePoll);
});

test('the walkthrough dialog wraps the video with the chapters beside it', function () {
    Storage::fake('artifacts');
    Storage::disk('artifacts')->put('chapters.json', json_encode([
        ['title' => 'Overview', 'startSeconds' => 12],
        ['title' => 'Export', 'startSeconds' => 75],
    ]));
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->running()->create(['description' => 'Usage page fields count as lookups', 'description_summary' => null]);
    Artifact::factory()->videoCut()->create(['yak_task_id' => $task->id]);
    Artifact::factory()->create(['yak_task_id' => $task->id, 'type' => 'video_chapters', 'role' => 'chapters', 'filename' => 'chapters.json', 'disk_path' => 'chapters.json']);

    $page = visit(route('tasks.show', $task))
        ->click('@walkthrough-poster')
        ->assertSeeIn('[data-testid="walkthrough-title"]', 'Usage page fields count as lookups')
        ->assertVisible('[data-testid="walkthrough-chapter-1"]');

    /** @var array{dialogBottom: float, cutTop: float, cutBottom: float, chaptersTop: float} $rects */
    $rects = $page->script('(() => {'
        . 'const dialog = document.querySelector(\'[data-testid="walkthrough-dialog"]\').getBoundingClientRect();'
        . 'const cut = document.querySelector(\'[data-testid="walkthrough-cut"]\').getBoundingClientRect();'
        . 'const chapters = document.querySelector(\'[data-testid="walkthrough-chapters"]\').closest("aside").getBoundingClientRect();'
        . 'return { dialogBottom: dialog.bottom, cutTop: cut.top, cutBottom: cut.bottom, chaptersTop: chapters.top };'
        . '})()');

    expect($rects['dialogBottom'] - $rects['cutBottom'])->toBeLessThan(24)
        ->and(abs($rects['chaptersTop'] - $rects['cutTop']))->toBeLessThan(2);

    $page->click('[data-testid="walkthrough-close"]')
        ->assertMissing('[data-testid="walkthrough-dialog"]');
});
