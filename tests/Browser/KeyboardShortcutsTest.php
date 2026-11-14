<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadDiff;
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
        ->keys('[data-testid="thread-composer-input"]', ['c', '?'])
        ->wait(1)
        ->assertMissing('[data-testid="new-thread-dialog"]')
        ->assertMissing('[data-testid="shortcuts-dialog"]')
        ->assertValue('[data-testid="thread-composer-input"]', 'c?');

    $page->keys('[data-testid="app-shell"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]')
        ->type('[data-testid="app-palette"]', 'open file')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->type('[data-testid="app-palette"]', 'alpha')
        ->keys('[data-testid="app-palette"]', 'Enter')
        ->assertSeeIn('[data-testid="files-open-path"]', 'alpha.txt')
        ->click('.cm-content')
        ->keys('.cm-content', ['c', '?'])
        ->wait(1)
        ->assertMissing('[data-testid="new-thread-dialog"]')
        ->assertMissing('[data-testid="shortcuts-dialog"]');

    expect($page->script("document.querySelector('.cm-content').innerText"))->toContain('c?');
});

test('G then P away from a thread page does nothing', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/');
    $page->script('window.__opened = []; window.open = (...args) => { window.__opened.push(args.map(String)); return null; };');
    $page->keys('[data-testid="app-shell"]', ['g'])
        ->keys('[data-testid="app-shell"]', ['p'])
        ->assertPathIs('/');

    // The harness's own script() call invokes window.open(null); any other call would be a preview open.
    $opened = array_filter($page->script('window.__opened'), fn (array $call) => $call[0] !== 'null');
    expect($opened)->toBeEmpty();
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

test('a hotkey does nothing while a menu is open', function () {
    $owner = userWithNewThreadSetup();
    $this->actingAs($owner);
    $thread = shortcutThread($owner);

    $page = visit(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]')
        ->click('[data-testid="thread-menu"]')
        ->assertPresent('[role="menu"]');
    // Base UI stops keydown at the menu itself, so the key is sent from outside it, as with focus elsewhere on the page.
    $page->script("document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '?', shiftKey: true, bubbles: true }))");
    $page->wait(1)
        ->assertMissing('[data-testid="shortcuts-dialog"]');
});

test('a repeated keydown does not run a hotkey', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/')->assertPresent('[data-testid="app-shell"]');
    $questionMark = "document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '?', shiftKey: true, repeat: %s, bubbles: true }))";

    $page->script(sprintf($questionMark, 'true'));
    $page->wait(1)->assertMissing('[data-testid="shortcuts-dialog"]');

    // The same key without `repeat` proves the app was ready to react to the first one.
    $page->script(sprintf($questionMark, 'false'));
    $page->assertVisible('[data-testid="shortcuts-dialog"]');
});

test('the slash key focuses the changed files filter without typing a slash', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->withFiles(1)->create();
    $this->actingAs(User::factory()->create());

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertPresent('[data-testid="changes-pane"]')
        ->keys('[data-testid="app-shell"]', ['/'])
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Filter files')
        ->assertScript('document.activeElement.value', '');
});

test('the bracket key toggles the sidebar', function () {
    $this->actingAs(User::factory()->create());

    visit('/threads/new')->assertAttributeMissing('[data-testid="sidebar-panel"]', 'aria-hidden')
        ->keys('[data-testid="app-shell"]', ['['])
        ->assertAttribute('[data-testid="sidebar-panel"]', 'aria-hidden', 'true');
});
