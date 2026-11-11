<?php

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The add_repository conversation card is covered by RepositoryCardTest and the projectConversation
 * tests: replaying stored events in a browser needs the Redis window and a Reverb bundle, as in StackJourneyTest.
 *
 * Journey 16: start a thread with three repositories, watch each one come up, read the
 * Environment tab, see Claude add a repository, switch a role and review per-repo changes.
 * The pull request per repository belongs to W4-MRPR; only the expected count is checked here.
 */
it('starts a thread with three repositories and shows per-repo progress', function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => fn (Request $request) => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/*' => Http::response(['description' => null, 'topics' => []]),
    ]);
    Queue::fake();

    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/api', 'is_default' => true]);
    Repository::factory()->create(['slug' => 'acme/web']);
    Repository::factory()->create(['slug' => 'acme/docs']);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->type('[data-testid="start-thread-description"]', 'Rename the invoice endpoint everywhere')
        ->click('[data-testid="add-repo"]')
        ->click('acme/web')
        ->click('[data-testid="add-repo"]')
        ->click('acme/docs')
        ->assertCount('[data-testid="repo-chip"]', 3)
        ->assertSeeIn('[data-testid="expected-pr-count"]', '3 pull requests expected')
        ->click('[data-testid="start-thread-submit"]')
        ->assertVisible('[data-testid="repo-progress"]')
        ->assertCount('[data-testid="repo-progress-row"]', 3);

    expect(Thread::query()->latest('id')->firstOrFail()->repositories()->count())->toBe(3);
});

it('shows the Environment rows, a role switch and the Changes sub-tabs', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $thread = Thread::factory()->create(['repo' => 'acme/app', 'owner_user_id' => $owner->id]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);
    ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/web', 'sandbox_path' => '/extras/web']);
    ThreadRepository::factory()->context()->create(['thread_id' => $thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs', 'added_by' => 'claude', 'add_reason' => 'Needs the docs', 'setup_status' => RepositorySetupStatus::Ready]);

    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 1, 'additions' => 1, 'deletions' => 0]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'app.php', 'additions' => 1, 'deletions' => 0]);
    $web = $diff->repositories()->create(['repo' => 'acme/web', 'role' => 'changes', 'base_sha' => 'a', 'head_sha' => 'b', 'files_count' => 1, 'additions' => 2]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'web.php', 'thread_diff_repository_id' => $web->id, 'additions' => 2, 'deletions' => 0]);
    $context = $diff->repositories()->create(['repo' => 'acme/docs', 'role' => 'context', 'base_sha' => 'a', 'head_sha' => 'b', 'files_count' => 1, 'additions' => 3, 'is_throwaway' => true]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'guide.md', 'thread_diff_repository_id' => $context->id, 'additions' => 3, 'deletions' => 0]);

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-panel-tab-environment"]')
        ->assertPresent('[data-testid="environment-repository-acme/app"]')
        ->assertPresent('[data-testid="environment-repository-acme/web"]')
        ->assertSeeIn('[data-testid="environment-repository-acme/docs"]', 'dataset not attached')
        ->click('[data-testid="thread-panel-tab-changes"]')
        ->assertPresent('[data-testid="changes-repository-tabs"]')
        ->click('[data-testid="changes-tab-acme/web"]')
        ->assertPresent('[data-testid="changes-file-web.php"]')
        ->click('[data-testid="changes-tab-acme/docs"]')
        ->assertPresent('[data-testid="changes-context-callout"]')
        ->click('[data-testid="changes-promote"]')
        ->assertMissing('[data-testid="changes-context-callout"]');

    expect($thread->repositories()->where('repo', 'acme/docs')->value('role'))->toBe(RepositoryRole::Changes);
});
