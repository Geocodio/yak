<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\RunnerState;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\ClaudeAccount;
use App\Models\CoOwnerRequest;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadRepository;
use App\Models\ThreadStar;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

function openPalette(mixed $page): void
{
    $page->keys('[data-testid="app-shell"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]');
}

test('the empty palette lists grouped actions with keycaps', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->assertSeeIn('[role="dialog"]', 'Create')
        ->assertSeeIn('[data-testid="palette-action-new-thread"]', 'New thread')
        ->assertSeeIn('[data-testid="palette-action-new-thread"]', 'C')
        ->assertSeeIn('[role="dialog"]', 'Go to')
        ->assertSeeIn('[data-testid="palette-action-go-repositories"]', 'Repositories')
        ->assertMissing('[data-testid="palette-action-next-needs-you"]')
        ->assertNoJavaScriptErrors();
});

test('each of create, new, start and spawn finds New thread', function (string $word) {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', $word)
        ->assertVisible('[data-testid="palette-action-new-thread"]');
})->with(['create', 'new', 'start', 'spawn']);

test('new followed by text opens the modal with that prompt', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'new fix the login bug')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="new-thread-dialog"]')
        ->assertValue('[data-testid="new-thread-dialog"] [data-testid="start-thread-description"]', 'fix the login bug');
});

test('an unavailable action is hidden when empty and disabled with a reason when typed', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->assertMissing('[data-testid="palette-action-next-needs-you"]')
        ->type('[data-testid="app-palette"]', 'next')
        ->assertSeeIn('[data-testid="palette-action-next-needs-you"]', 'Nothing needs you')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="app-palette"]');
});

test('Escape closes the palette and the query resets on reopen', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'repo')
        ->keys('[data-testid="app-palette"]', 'Escape')
        ->assertMissing('[data-testid="app-palette"]');

    openPalette($page);

    $page->assertValue('[data-testid="app-palette"]', '');
});

function paletteThread(User $owner, array $attributes = []): Thread
{
    $thread = Thread::factory()->private($owner)->create([
        'repo' => 'acme/widgets',
        'branch' => 'yak/widgets',
        'sandbox_state' => ThreadSandboxState::Running,
        'runner_state' => RunnerState::Idle,
        ...$attributes,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);
    YakTask::factory()->create(['thread_id' => $thread->id]);

    return $thread;
}

test('on a thread page, typing preview and Enter opens the preview URL in a new window', function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['preview_hostname' => 'widgets.yak.example.com']);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    $page->script('window.__opened = []; window.open = (...args) => { window.__opened.push(args.map(String)); return null; };');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'preview')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertMissing('[data-testid="app-palette"]');

    // The harness itself calls window.open once; the palette's call is the last one.
    expect(last($page->script('window.__opened')))->toBe(['https://widgets.yak.example.com/', '_blank', 'noopener']);
});

test('the empty palette on a thread page starts with a This thread section', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->assertSeeIn('[role="dialog"]', 'This thread')
        ->assertVisible('[data-testid="palette-action-thread-show-files"]');

    $order = $page->script("(() => { const text = document.querySelector('[role=\"dialog\"]').innerText.toLowerCase(); return text.indexOf('this thread') >= 0 && text.indexOf('this thread') < text.indexOf('create'); })()");
    expect($order)->toBeTrue();
});

test('Show terminal opens the terminal panel', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'terminal')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="terminal-panel"]');
});

test('Stop the turn asks for confirmation and stops only after confirm', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);
    TaskRun::factory()->create(['yak_task_id' => $thread->tasks()->first()->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $this->mock(ThreadTurns::class, fn ($mock) => $mock->shouldReceive('stop')->once());

    $page = visit(route('threads.show', $thread))->assertVisible('[data-testid="thread-stop"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'stop the turn')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Stop the turn?')
        ->assertSee('Yak stops work on this thread now. Changes made so far stay in the sandbox.')
        ->click('Cancel')
        ->assertMissing('[data-testid="confirm-dialog"]')
        ->assertVisible('[data-testid="app-palette"]')
        ->assertVisible('[data-testid="thread-stop"]');

    $page->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Stop the turn?')
        ->click('[data-testid="palette-confirm-accept"]')
        ->assertMissing('[data-testid="app-palette"]');
});

test('Star thread toggles the header star', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);

    $page = visit(route('threads.show', $thread))->resize(1440, 900)->assertAttribute('[data-testid="thread-star"]', 'aria-pressed', 'false');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'star thread')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertAttribute('[data-testid="thread-star"]', 'aria-pressed', 'true');

    expect(ThreadStar::query()->where('user_id', $owner->id)->count())->toBe(1);
});

test('a starred thread offers Remove star', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);
    ThreadStar::query()->create(['user_id' => $owner->id, 'thread_id' => $thread->id]);

    $page = visit(route('threads.show', $thread))->resize(1440, 900)->assertAttribute('[data-testid="thread-star"]', 'aria-pressed', 'true');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'remove star')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertAttribute('[data-testid="thread-star"]', 'aria-pressed', 'false');

    expect(ThreadStar::query()->count())->toBe(0);
});

test('Close thread asks first, does nothing on Cancel and closes after confirm', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['sandbox_state' => ThreadSandboxState::None]);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy')->zeroOrMoreTimes();
    app()->instance(ThreadSandbox::class, $sandbox);

    $page = visit(route('threads.show', $thread))->resize(1440, 900)->assertPresent('[data-testid="thread-menu"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'close thread')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Close this thread?')
        ->click('Cancel')
        ->assertMissing('[data-testid="confirm-dialog"]')
        ->assertVisible('[data-testid="app-palette"]');

    expect($thread->refresh()->status)->toBe(ThreadStatus::Open);

    $page->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Close this thread?')
        ->click('[data-testid="palette-confirm-accept"]')
        ->assertMissing('[data-testid="app-palette"]');

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed);
});

test('a closed thread offers Delete but not Close', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['status' => ThreadStatus::Closed]);

    $page = visit(route('threads.show', $thread))->resize(1440, 900)->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'thread')
        ->assertVisible('[data-testid="palette-action-thread-delete"]')
        ->assertMissing('[data-testid="palette-action-thread-close"]');
});

test('Delete thread asks first, does nothing on Cancel and deletes after confirm', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['sandbox_state' => ThreadSandboxState::None]);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('destroy')->zeroOrMoreTimes();
    app()->instance(ThreadSandbox::class, $sandbox);

    $page = visit(route('threads.show', $thread))->resize(1440, 900)->assertPresent('[data-testid="thread-menu"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'delete thread')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Delete this thread?')
        ->assertSee('Messages, files and sub-threads are removed for good.')
        ->click('Cancel')
        ->assertMissing('[data-testid="confirm-dialog"]')
        ->assertVisible('[data-testid="app-palette"]');

    expect(Thread::query()->withoutGlobalScopes()->whereKey($thread->id)->exists())->toBeTrue();

    $page->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Delete this thread?')
        ->click('[data-testid="palette-confirm-accept"]')
        ->assertMissing('[data-testid="app-palette"]');

    expect(Thread::query()->withoutGlobalScopes()->whereKey($thread->id)->exists())->toBeFalse();
});

test('Share is absent for a viewer who is not the owner', function () {
    $this->actingAs(User::factory()->create());
    $thread = paletteThread(User::factory()->create(), ['visibility' => 'team']);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'share')
        ->assertMissing('[data-testid="palette-action-thread-share"]')
        ->assertMissing('[data-testid="palette-action-thread-make-private"]');
});

test('Share is offered to the owner', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'share')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="share-dialog"]');
});

test('Open file searches the file tree and opens the file in the files panel', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandboxes);
    $thread = paletteThread($owner);
    $sandboxes->queueExecResult(Process::result("f\t3\talpha.txt\0f\t3\tbeta.md\0"));
    $sandboxes->queueExecResult(Process::result("f\t3\talpha.txt\0f\t3\tbeta.md\0"));
    $bytes = "# beta\n";
    $sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'open file')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="palette-step"]')
        ->assertValue('[data-testid="app-palette"]', '')
        ->assertSeeIn('[role="dialog"]', 'alpha.txt')
        ->type('[data-testid="app-palette"]', 'beta')
        ->assertDontSeeIn('[role="dialog"]', 'alpha.txt')
        ->assertSeeIn('[role="dialog"]', 'beta.md')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertMissing('[data-testid="app-palette"]')
        ->assertSeeIn('[data-testid="files-open-path"]', 'beta.md');
});

test('Make private states that nobody else can read the thread', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['visibility' => 'team']);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'make private')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Make this thread private?')
        ->assertSee('Only you can see this thread. Nobody else can read its conversation, changes or details.');
});

test('typing team on a private thread finds Share and no one-step visibility change', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'team')
        ->assertVisible('[data-testid="palette-action-thread-share"]')
        ->assertMissing('[data-testid="palette-action-thread-make-visible"]')
        ->assertMissing('[data-testid="palette-action-thread-make-private"]');
});

test('typing a thread title lists it under Threads and Enter opens it', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Rework invoice export']);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'invoice')
        ->assertSeeIn('[role="dialog"]', 'Threads')
        ->assertSeeIn('[data-testid="palette-action-thread:' . $thread->public_id . '"]', 'Rework invoice export')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertPathIs(route('threads.show', $thread, false));
});

test('a closed thread shows a closed hint', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Retire the legacy exporter', 'status' => 'closed']);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'legacy')
        ->assertSeeIn('[data-testid="palette-action-thread:' . $thread->public_id . '"]', 'acme/widgets · closed');
});

test('Tab on a thread result lists its actions, and Backspace returns to the results', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Rework invoice export']);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'invoice')
        ->assertVisible('[data-testid="palette-action-thread:' . $thread->public_id . '"]')
        ->keys('[data-testid="app-palette"]', 'Tab')
        ->assertVisible('[data-testid="palette-action-thread-open"]')
        ->assertVisible('[data-testid="palette-action-thread-copy-link"]')
        ->assertSeeIn('[data-testid="palette-action-thread-open-preview"]', 'This thread has no preview yet')
        ->keys('[data-testid="app-palette"]', 'Backspace')
        ->assertVisible('[data-testid="palette-action-thread:' . $thread->public_id . '"]')
        ->assertMissing('[data-testid="palette-action-thread-open"]');
});

test('a repository result offers New thread in this repository', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/website', 'name' => 'website', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'website')
        ->assertVisible('[data-testid="palette-action-repository:acme/website"]')
        ->keys('[data-testid="app-palette"]', 'Tab')
        ->assertSeeIn('[data-testid="palette-action-repository-new-thread"]', 'New thread in this repository')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="new-thread-dialog"]');
});

test('Enter on a pull request result opens GitHub in a new window', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Speed up the importer']);
    ThreadRepository::query()->where('thread_id', $thread->id)->update(['pull_request_url' => 'https://github.com/acme/widgets/pull/12', 'pull_request_number' => 12]);

    $page = visit('/');
    $page->script('window.__opened = []; window.open = (...args) => { window.__opened.push(args.map(String)); return null; };');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'importer')
        ->assertVisible('[data-testid="palette-action-pull-request:acme/widgets#12"]')
        ->keys('[data-testid="app-palette"]', ['ArrowDown', 'Enter'])
        ->assertMissing('[data-testid="app-palette"]');

    expect(last($page->script('window.__opened')))->toBe(['https://github.com/acme/widgets/pull/12', '_blank', 'noopener']);
});

test('results from an earlier query do not replace the current ones', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $alpha = paletteThread($owner, ['title' => 'Alpha report']);
    $beta = paletteThread($owner, ['title' => 'Beta report']);

    $page = visit('/');
    // The answer for "alpha" is held until released, then delivered after the answer for "beta".
    $page->script(<<<'JS'
        window.__alphaDelivered = false;
        const realFetch = window.fetch;
        window.fetch = (input, init) => {
            if (!String(input).includes('query=alpha')) {
                return realFetch(input, init);
            }
            return new Promise((resolve) => {
                window.__releaseAlpha = () => realFetch(input).then((response) => {
                    resolve(response);
                    setTimeout(() => (window.__alphaDelivered = true), 100);
                });
            });
        };
    JS);
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'alpha')
        ->assertScript('typeof window.__releaseAlpha === "function"')
        ->type('[data-testid="app-palette"]', 'beta')
        ->assertVisible('[data-testid="palette-action-thread:' . $beta->public_id . '"]');

    $page->script('window.__releaseAlpha()');

    $page->assertScript('window.__alphaDelivered === true')
        ->assertVisible('[data-testid="palette-action-thread:' . $beta->public_id . '"]')
        ->assertMissing('[data-testid="palette-action-thread:' . $alpha->public_id . '"]');
});

test('an action picked before ranks first among equal matches', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'p')
        ->assertScript('document.querySelector(\'[data-testid="palette-action-go-pr-reviews"]\').compareDocumentPosition(document.querySelector(\'[data-testid="palette-action-go-prompts"]\')) & Node.DOCUMENT_POSITION_FOLLOWING', 4)
        ->clear('[data-testid="app-palette"]')
        ->click('[data-testid="palette-action-go-prompts"]')
        ->assertPathIs('/prompts/system');

    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'p')
        ->assertScript('document.querySelector(\'[data-testid="palette-action-go-prompts"]\').compareDocumentPosition(document.querySelector(\'[data-testid="palette-action-go-pr-reviews"]\')) & Node.DOCUMENT_POSITION_FOLLOWING', 4);
});

test('Repositories lists Add repository', function () {
    $this->actingAs(User::factory()->create());
    Repository::factory()->create(['slug' => 'acme/website']);

    $page = visit('/repos');
    openPalette($page);

    $page->assertSeeIn('[data-testid="palette-action-repositories-add"]', 'Add repository')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertPathIs('/repos/create');
});

test('a repository page lists New thread in this repository, Open on GitHub and Risk profile', function () {
    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create(['slug' => 'acme/website', 'pr_review_enabled' => true]);

    $page = visit(route('repos.edit', $repository));
    openPalette($page);

    $page->assertSeeIn('[data-testid="palette-action-this-repository-new-thread"]', 'New thread in this repository')
        ->assertSeeIn('[data-testid="palette-action-this-repository-open-github"]', 'Open on GitHub')
        ->assertSeeIn('[data-testid="palette-action-this-repository-risk-profile"]', 'Open the risk profile');
});

test('a PR review page lists Open the pull request on GitHub', function () {
    $this->actingAs(User::factory()->create());
    $review = PrReview::factory()->create(['repo' => 'acme/website', 'pr_number' => 12]);

    $page = visit(route('pr-reviews.for-pr', ['repoSlug' => $review->repo, 'prNumber' => $review->pr_number]));
    openPalette($page);

    $page->assertSeeIn('[data-testid="palette-action-review-open-pull-request"]', 'Open the pull request on GitHub');
});

test('a deployment page lists Open the preview and Destroy the preview, and destroy asks for confirmation', function () {
    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create(['slug' => 'acme/website']);
    Bus::fake();
    $deployment = BranchDeployment::factory()->running()->create(['repository_id' => $repository->id]);

    $page = visit(route('deployments.show', $deployment));
    openPalette($page);

    $page->assertSeeIn('[role="dialog"]', 'This deployment')
        ->assertSeeIn('[data-testid="palette-action-deployment-open-preview"]', 'Open the preview')
        ->assertSeeIn('[data-testid="palette-action-deployment-destroy"]', 'Destroy the preview')
        ->type('[data-testid="app-palette"]', 'destroy')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="palette-confirm"]')
        ->click('[data-testid="palette-confirm-cancel"]')
        ->assertMissing('[data-testid="palette-confirm"]');

    Bus::assertNotDispatched(DestroyDeploymentJob::class);

    $page->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSee('Destroy this deployment')
        ->click('[data-testid="palette-confirm-accept"]')
        ->assertMissing('[data-testid="app-palette"]');

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

test('SSH keys lists Add SSH key and it opens the add form', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/settings/ssh-keys');
    openPalette($page);

    $page->assertSeeIn('[data-testid="palette-action-ssh-add-key"]', 'Add SSH key')
        ->type('[data-testid="app-palette"]', 'add ssh')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="ssh-key-public"]');
});

test('Claude accounts lists Add Claude account and it opens the add dialog', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/settings/claude-accounts');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'add claude')
        ->assertVisible('[data-testid="palette-action-claude-accounts-add"]')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="account-save"]');
});

test('Open a pull request is disabled with the reason while a co-owner has to approve', function () {
    $this->actingAs(User::factory()->create());
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->withFiles(1)->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => 'yak/work']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->id, 'status' => CoOwnerRequestStatus::Pending]);

    $page = visit(route('threads.show', $thread))->assertVisible('[data-testid="pull-request-held"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'open a pull request')
        ->assertSeeIn('[data-testid="palette-action-thread-create-pull-request"]', 'Waiting for a co-owner')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertMissing('[data-testid="palette-confirm"]')
        ->assertVisible('[data-testid="app-palette"]');
});

test('Filter changed files from the palette leaves the filter input focused', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->withFiles(2)->create();
    $this->actingAs(User::factory()->create());

    $page = visit(route('threads.show', $thread))->click('[data-testid="thread-dock-toggle-files"]')->assertPresent('[data-testid="changes-filter-hint"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'filter changed')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertMissing('[data-testid="app-palette"]')
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Filter files');
});

test('Focus the composer from the palette leaves the composer focused', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner);

    $page = visit(route('threads.show', $thread))->assertVisible('[data-testid="thread-composer-input"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'focus the composer')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertMissing('[data-testid="app-palette"]')
        ->assertScript('document.activeElement.getAttribute("data-testid")', 'thread-composer-input');
});

test('a search answer that lands after the palette closed does not show on reopen', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Gamma report']);

    $page = visit('/');
    $page->script(<<<'JS'
        window.__gammaDelivered = false;
        const realFetch = window.fetch;
        window.fetch = (input, init) => {
            if (!String(input).includes('query=gamma')) {
                return realFetch(input, init);
            }
            return new Promise((resolve) => {
                window.__releaseGamma = () => realFetch(input).then((response) => {
                    resolve(response);
                    setTimeout(() => (window.__gammaDelivered = true), 100);
                });
            });
        };
    JS);
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'gamma')
        ->assertScript('typeof window.__releaseGamma === "function"')
        ->keys('[data-testid="app-palette"]', 'Escape')
        ->assertMissing('[data-testid="app-palette"]');

    $page->script('window.__releaseGamma()');
    $page->assertScript('window.__gammaDelivered === true');
    openPalette($page);

    $page->assertMissing('[data-testid="palette-action-thread:' . $thread->public_id . '"]');
});

test('Drop a sub-thread asks first, keeps it on Cancel and closes it after confirm', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $parent = paletteThread($owner);
    $child = Thread::factory()->child($parent)->create(['name' => 'builder', 'title' => 'Build the client']);
    StackThreadMessage::factory()->create([
        'root_thread_id' => $parent->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $parent->id,
        'kind' => StackThreadMessageKind::Failure,
        'text' => 'Sub-thread builder failed before finishing.',
        'delivered_at' => now(),
    ]);

    $page = visit(route('threads.show', $parent))->assertPresent('[data-testid="stack-child-' . $child->public_id . '"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'drop a sub')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="palette-action-thread-stack-drop-' . $child->public_id . '"]')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSeeIn('[data-testid="palette-confirm"]', 'Drop builder?')
        ->click('[data-testid="palette-confirm-cancel"]')
        ->assertMissing('[data-testid="palette-confirm"]');

    expect($child->fresh()->isOpen())->toBeTrue();

    $page->keys('[data-testid="app-palette"]', 'Enter')
        ->click('[data-testid="palette-confirm-accept"]')
        ->assertMissing('[data-testid="app-palette"]');

    expect($child->fresh()->isOpen())->toBeFalse();
});

test('Take over a sub-thread asks first and marks it taken over after confirm', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $parent = paletteThread($owner);
    $child = Thread::factory()->child($parent)->create(['name' => 'builder', 'title' => 'Build the client']);
    StackThreadMessage::factory()->create([
        'root_thread_id' => $parent->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $parent->id,
        'kind' => StackThreadMessageKind::Failure,
        'text' => 'Sub-thread builder failed before finishing.',
        'delivered_at' => now(),
    ]);

    $page = visit(route('threads.show', $parent))->assertPresent('[data-testid="stack-child-' . $child->public_id . '"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'take over')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertVisible('[data-testid="palette-action-thread-stack-take-over-' . $child->public_id . '"]')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSeeIn('[data-testid="palette-confirm"]', 'Take over builder?')
        ->click('[data-testid="palette-confirm-accept"]')
        ->assertMissing('[data-testid="app-palette"]');

    expect($child->fresh()->taken_over_at)->not->toBeNull();
});

/** Sends real key presses to whichever element has focus, the way a keyboard does, instead of focusing a selector first. */
function pressKeys(mixed $page, string ...$keys): void
{
    foreach ($keys as $key) {
        $page->page()->keyDown($key);
        $page->page()->keyUp($key);
    }
}

function typeKeys(mixed $page, string $text): void
{
    pressKeys($page, ...str_split($text));
}

const PALETTE_INPUT_IS_FOCUSED = 'document.activeElement?.getAttribute("data-testid") === "app-palette"';

test('Tab on a thread result keeps focus in the input, so typing filters the step', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Rework invoice export']);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'invoice');
    $page->assertVisible('[data-testid="palette-action-thread:' . $thread->public_id . '"]')->wait(0.5);
    pressKeys($page, 'Tab');

    $page->assertVisible('[data-testid="palette-step"]')
        ->assertScript(PALETTE_INPUT_IS_FOCUSED, true);

    typeKeys($page, 'copy');
    $page->assertVisible('[data-testid="palette-action-thread-copy-link"]')
        ->assertMissing('[data-testid="palette-action-thread-open"]')
        ->assertValue('[data-testid="app-palette"]', 'copy');
});

test('Backspace on the empty step input returns to the results with the input focused and the query restored', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Rework invoice export']);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'invoice');
    $page->assertVisible('[data-testid="palette-action-thread:' . $thread->public_id . '"]')->wait(0.5);
    pressKeys($page, 'Tab');
    $page->assertVisible('[data-testid="palette-step"]');
    pressKeys($page, 'Backspace');

    $page->assertMissing('[data-testid="palette-step"]')
        ->assertScript(PALETTE_INPUT_IS_FOCUSED, true)
        ->assertValue('[data-testid="app-palette"]', 'invoice');
});

test('Enter on Open file keeps focus in the step input', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    app()->instance(SandboxManager::class, $sandboxes = new FakeSandboxManager);
    $thread = paletteThread($owner);
    $sandboxes->queueExecResult(Process::result("f\t3\talpha.txt\0f\t3\tbeta.md\0"));

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'open file');
    $page->assertVisible('[data-testid="palette-action-thread-open-file"]')->wait(0.5);
    pressKeys($page, 'Enter');

    $page->assertVisible('[data-testid="palette-step"]')
        ->assertScript(PALETTE_INPUT_IS_FOCUSED, true);
});

test('Escape in a step returns to the root list with the input focused', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Rework invoice export']);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'invoice');
    $page->assertVisible('[data-testid="palette-action-thread:' . $thread->public_id . '"]')->wait(0.5);
    pressKeys($page, 'Tab');
    $page->assertVisible('[data-testid="palette-step"]');
    pressKeys($page, 'Escape');

    $page->assertMissing('[data-testid="palette-step"]')
        ->assertVisible('[data-testid="app-palette"]')
        ->assertScript(PALETTE_INPUT_IS_FOCUSED, true);
});

test('a plain key typed in a step goes into the input and does not trigger a hotkey', function () {
    $owner = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $owner->id, 'validated_at' => now()]);
    $this->actingAs($owner);
    $thread = paletteThread($owner, ['title' => 'Rework invoice export']);

    $page = visit('/');
    openPalette($page);

    $page->type('[data-testid="app-palette"]', 'invoice');
    $page->assertVisible('[data-testid="palette-action-thread:' . $thread->public_id . '"]')->wait(0.5);
    pressKeys($page, 'Tab');
    $page->assertVisible('[data-testid="palette-step"]');
    pressKeys($page, 'c');

    $page->wait(0.5)
        ->assertVisible('[data-testid="app-palette"]')
        ->assertMissing('[data-testid="new-thread-dialog"]')
        ->assertValue('[data-testid="app-palette"]', 'c');
});

test('the open palette blocks plain-key hotkeys even when focus is outside the input', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    $page = visit('/');
    openPalette($page);

    $page->script('(() => { const popup = document.querySelector("[data-command-palette]"); popup.setAttribute("tabindex", "-1"); popup.focus(); })()');
    $page->assertScript('document.activeElement.hasAttribute("data-command-palette")', true);
    pressKeys($page, 'c');

    $page->wait(0.5)
        ->assertVisible('[data-testid="app-palette"]')
        ->assertMissing('[data-testid="new-thread-dialog"]');
});
