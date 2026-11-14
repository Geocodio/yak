<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadTurns;
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
        ->click('[role="alertdialog"] button:last-of-type')
        ->assertMissing('[data-testid="app-palette"]');
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
