<?php

use App\Enums\RepositoryRole;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
use App\Models\User;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);

    $this->thread = Thread::factory()->create(['repo' => 'acme/app', 'owner_user_id' => $this->owner->id]);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/app']);
    ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/web', 'sandbox_path' => '/extras/web']);
    ThreadRepository::factory()->context()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs']);

    $diff = ThreadDiff::factory()->for($this->thread)->create(['files_count' => 1, 'additions' => 1, 'deletions' => 0]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'app.php', 'additions' => 1, 'deletions' => 0]);
    $web = $diff->repositories()->create(['repo' => 'acme/web', 'role' => 'changes', 'base_sha' => 'a', 'head_sha' => 'b', 'files_count' => 1, 'additions' => 2]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'web.php', 'thread_diff_repository_id' => $web->id, 'additions' => 2, 'deletions' => 0]);
    $docs = $diff->repositories()->create(['repo' => 'acme/docs', 'role' => 'context', 'base_sha' => 'a', 'head_sha' => 'b', 'files_count' => 1, 'additions' => 3, 'is_throwaway' => true]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'guide.md', 'thread_diff_repository_id' => $docs->id, 'additions' => 3, 'deletions' => 0]);
});

test('it switches between repo tabs and promotes a context repo', function () {
    $page = visit(route('threads.show', $this->thread));

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->assertSeeIn('[data-testid="changes-summary"]', '2 files in 2 repos')
        ->assertSeeIn('[data-testid="changes-scratch-note"]', 'plus 1 scratch file in acme/docs')
        ->assertSeeIn('[data-testid="changes-pull-request-sentence"]', '2 pull requests will open, one per repo with changes.')
        ->assertPresent('[data-testid="changes-file-web.php"]')
        ->click('[data-testid="changes-tab-acme/web"]')
        ->assertPresent('[data-testid="changes-file-web.php"]')
        ->assertMissing('[data-testid="changes-file-app.php"]')
        ->click('[data-testid="changes-tab-acme/docs"]')
        ->assertSeeIn('[data-testid="changes-context-callout"]', 'acme/docs is context. No pull request is opened')
        ->click('[data-testid="changes-promote"]')
        ->assertMissing('[data-testid="changes-context-callout"]');

    expect($this->thread->repositories()->where('repo', 'acme/docs')->value('role'))->toBe(RepositoryRole::Changes);
});

test('a single repo thread has no sub tabs', function () {
    $thread = Thread::factory()->create(['owner_user_id' => $this->owner->id]);
    ThreadDiff::factory()->for($thread)->withFiles(2)->create();

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertSeeIn('[data-testid="changes-summary"]', '2 files changed')
        ->assertMissing('[data-testid="changes-repository-tabs"]');
});

test('repo tabs show an avatar and a count pill', function () {
    visit(route('threads.show', $this->thread))
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertSeeIn('[data-testid="changes-tab-all"]', 'All repos')
        ->assertSeeIn('[data-testid="changes-tab-all"] [data-testid="changes-tab-count"]', '2')
        ->assertPresent('[data-testid="changes-tab-acme/web"] [data-testid="repo-avatar"]')
        ->assertSeeIn('[data-testid="changes-tab-acme/web"] [data-testid="changes-tab-count"]', '1');
});

test('the all repos view shows one card per repo with badge and footer', function () {
    visit(route('threads.show', $this->thread))
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertSeeIn('[data-testid="changes-repo-card-acme/app"]', 'Primary')
        ->assertSeeIn('[data-testid="changes-repo-card-acme/web"]', 'Changes')
        ->assertPresent('[data-testid="changes-repo-card-acme/web"] [data-testid="changes-file-web.php"]')
        ->assertSeeIn('[data-testid="changes-repo-card-acme/docs"]', 'Context')
        ->assertSeeIn('[data-testid="changes-repo-card-acme/docs"]', 'No pull request')
        ->assertSeeIn('[data-testid="changes-repo-card-acme/docs"]', 'Review');
});
