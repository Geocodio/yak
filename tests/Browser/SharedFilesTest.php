<?php

use App\Enums\ThreadVisibility;
use App\Models\Artifact;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

function threadWithSharedFiles(ThreadVisibility $visibility): array
{
    Storage::fake('artifacts');
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['visibility' => $visibility, 'owner_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $report = Artifact::factory()->shared('research-report.html')->create(['yak_task_id' => $task->id, 'size_bytes' => 212_000]);
    $chart = Artifact::factory()->shared('latency.png')->create(['yak_task_id' => $task->id]);
    $csv = Artifact::factory()->shared('results.csv')->create(['yak_task_id' => $task->id]);

    return [$user, $thread, $report, $chart, $csv];
}

it('shows the shared files card with an action per file type', function () {
    [$user, $thread] = threadWithSharedFiles(ThreadVisibility::Team);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertPresent('[data-testid="thread-shared-files"]')
        ->assertSeeIn('[data-testid="thread-shared-files"]', '3 shared files')
        ->assertSeeIn('[data-testid="shared-file-row"][data-file-name="research-report.html"]', 'Open')
        ->assertSeeIn('[data-testid="shared-file-row"][data-file-name="latency.png"]', 'View')
        ->assertPresent('[data-testid="shared-file-row"][data-file-name="latency.png"] [data-testid="shared-file-thumb"]')
        ->assertSeeIn('[data-testid="shared-file-row"][data-file-name="results.csv"]', 'Download')
        ->assertMissing('[data-testid="shared-file-team-badge"]')
        ->assertNoJavaScriptErrors();
});

it('lists the files in the Overview rail, newest first, with the Team badge', function () {
    [$user, $thread, $report] = threadWithSharedFiles(ThreadVisibility::Team);
    $report->update(['shared_with_team' => true]);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="overview-shared-files"]', 'Files')
        ->assertSeeIn('[data-testid="rail-shared-files-count"]', '3')
        ->assertScript("[...document.querySelectorAll('[data-testid=\"rail-shared-file-name\"]')].map(el => el.textContent).join(',')", 'results.csv,latency.png,research-report.html')
        ->assertPresent('[data-testid="rail-shared-file"][data-file-name="research-report.html"] [data-testid="shared-file-team-badge"]')
        ->assertNoJavaScriptErrors();
});

it('hides the rail section and the card when the thread has no shared files', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertMissing('[data-testid="overview-shared-files"]')
        ->assertMissing('[data-testid="thread-shared-files"]');
});

it('asks before sharing a file from a private thread and then marks it Team', function () {
    [$user, $thread, $report] = threadWithSharedFiles(ThreadVisibility::Private);
    $this->actingAs($user);
    $row = '[data-testid="shared-file-row"][data-file-name="research-report.html"]';

    visit(route('threads.show', $thread))
        ->click("{$row} [data-testid=\"shared-file-share\"]")
        ->assertPresent('[data-testid="shared-file-share-dialog"]')
        ->click('[data-testid="shared-file-team-toggle"]')
        ->assertSeeIn('[data-testid="shared-file-confirm"]', 'Share this file with the team?')
        ->assertSeeIn('[data-testid="shared-file-confirm"]', 'This thread is private.')
        ->click('[data-testid="shared-file-confirm-share"]')
        ->assertPresent("{$row} [data-testid=\"shared-file-team-badge\"]")
        ->assertNoJavaScriptErrors();

    expect($report->fresh()->shared_with_team)->toBeTrue();
});

it('cancels the confirm without sharing', function () {
    [$user, $thread, $report] = threadWithSharedFiles(ThreadVisibility::Private);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click('[data-testid="shared-file-row"][data-file-name="research-report.html"] [data-testid="shared-file-share"]')
        ->click('[data-testid="shared-file-team-toggle"]')
        ->click('[data-testid="shared-file-confirm-cancel"]')
        ->assertMissing('[data-testid="shared-file-confirm"]');

    expect($report->fresh()->shared_with_team)->toBeFalse();
});

it('shares at once on a team thread with no confirm', function () {
    [$user, $thread, $report] = threadWithSharedFiles(ThreadVisibility::Team);
    $this->actingAs($user);
    $row = '[data-testid="shared-file-row"][data-file-name="research-report.html"]';

    visit(route('threads.show', $thread))
        ->click("{$row} [data-testid=\"shared-file-share\"]")
        ->click('[data-testid="shared-file-team-toggle"]')
        ->assertMissing('[data-testid="shared-file-confirm"]')
        ->assertSeeIn('[data-testid="shared-file-share-dialog"]', 'Anyone signed in to Yak can open this file.');

    expect($report->fresh()->shared_with_team)->toBeTrue();
});
