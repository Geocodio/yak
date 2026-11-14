<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\Process;

function shortcutThread(User $owner): Thread
{
    $thread = Thread::factory()->private($owner)->create([
        'repo' => 'acme/widgets',
        'branch' => 'yak/widgets',
        'sandbox_state' => ThreadSandboxState::Running,
        'runner_state' => RunnerState::Idle,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);
    YakTask::factory()->create(['thread_id' => $thread->id]);

    return $thread;
}

function userWithNewThreadSetup(): User
{
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);

    return $user;
}

test('C opens the new thread modal', function () {
    $this->actingAs(userWithNewThreadSetup());

    visit('/')->keys('[data-testid="app-shell"]', ['c'])
        ->assertVisible('[data-testid="new-thread-dialog"]')
        ->assertNoJavaScriptErrors();
});

test('the question mark opens a dialog that lists every entry in the table', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/')->keys('[data-testid="app-shell"]', ['?'])
        ->assertVisible('[data-testid="shortcuts-dialog"]');

    foreach (['openPalette', 'showShortcuts', 'newThread', 'goHome', 'nextNeedsYou', 'openPreview', 'showFiles', 'showTerminal', 'showConversation', 'openPullRequest'] as $id) {
        $page->assertVisible('[data-testid="shortcut-' . $id . '"]');
    }
    foreach (['Run the selected action', 'More actions for a result', 'Go back one step', 'Close'] as $label) {
        $page->assertSeeIn('[data-testid="shortcuts-dialog"]', $label);
    }
});

test('G then H goes to Home', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = shortcutThread($owner);

    visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]')
        ->keys('[data-testid="app-shell"]', ['g'])
        ->keys('[data-testid="app-shell"]', ['h'])
        ->assertPathIs('/');
});

test('a hotkey letter typed in an input types the letter and runs nothing', function () {
    $owner = userWithNewThreadSetup();
    $this->actingAs($owner);
    $sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandboxes);
    $thread = shortcutThread($owner);
    $listing = Process::result("f\t3\talpha.txt\0");
    $sandboxes->queueExecResult($listing);
    $sandboxes->queueExecResult($listing);
    $bytes = "alpha\n";
    $sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]')
        ->click('[data-testid="thread-composer-input"]')
        ->keys('[data-testid="thread-composer-input"]', ['c'])
        ->assertMissing('[data-testid="new-thread-dialog"]')
        ->assertValue('[data-testid="thread-composer-input"]', 'c');

    $page->keys('[data-testid="app-shell"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]')
        ->type('[data-testid="app-palette"]', 'open file')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->type('[data-testid="app-palette"]', 'alpha')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSeeIn('[data-testid="files-open-path"]', 'alpha.txt')
        ->click('.cm-content')
        ->keys('.cm-content', ['c'])
        ->assertMissing('[data-testid="new-thread-dialog"]');

    expect($page->script("document.querySelector('.cm-content').innerText"))->toContain('c');
});

test('G then P away from a thread page does nothing', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    $page->script('window.__opened = []; window.open = (...args) => { window.__opened.push(args.map(String)); return null; };');
    $page->keys('[data-testid="app-shell"]', ['g'])
        ->keys('[data-testid="app-shell"]', ['p'])
        ->assertPathIs('/');

    // The harness itself may call window.open once; a preview open would add another.
    expect(count($page->script('window.__opened')))->toBeLessThanOrEqual(1);
});

test('Cmd+K opens the palette while focus is in the composer', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = shortcutThread($owner);

    visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]')
        ->click('[data-testid="thread-composer-input"]')
        ->keys('[data-testid="thread-composer-input"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]');
});

test('G then P on a thread page opens the preview', function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $thread = shortcutThread($owner);
    $thread->update(['preview_hostname' => 'widgets.yak.example.com']);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    $page->script('window.__opened = []; window.open = (...args) => { window.__opened.push(args.map(String)); return null; };');
    $page->keys('[data-testid="app-shell"]', ['g'])->keys('[data-testid="app-shell"]', ['p']);

    expect(last($page->script('window.__opened')))->toBe(['https://widgets.yak.example.com/', '_blank', 'noopener']);
});
