<?php

// Journey 14. Written, not run in this track: the integrator runs Playwright.

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
});

function filesEditorThread(RunnerState $runner = RunnerState::Idle): Thread
{
    $thread = Thread::factory()->private(test()->owner)->create(['sandbox_state' => ThreadSandboxState::Running, 'runner_state' => $runner]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);

    return $thread;
}

function queueTree($test): void
{
    $test->sandboxes->queueExecResult(Process::result("f\t3\ta.txt\0"));
}

function queueFileRead($test, string $bytes): void
{
    $test->sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));
}

function openFilesPanel($test, Thread $thread)
{
    return visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->click('[data-testid="files-tree-item"]');
}

it('opens a file, edits it and saves with Cmd+S', function () {
    $thread = filesEditorThread();
    queueTree($this);
    queueFileRead($this, 'old');
    queueFileRead($this, 'old');
    $this->sandboxes->queueExecResult(Process::result());
    queueFileRead($this, 'oldx');

    $page = openFilesPanel($this, $thread);

    $page->assertPresent('[data-testid="file-editor"]')
        ->click('[data-testid="file-editor"] .cm-content')
        ->keys('[data-testid="file-editor"] .cm-content', 'x')
        ->assertSeeIn('[data-testid="file-save-state"]', 'Unsaved')
        ->keys('[data-testid="file-editor"] .cm-content', 'ControlOrMeta+s')
        ->assertSeeIn('[data-testid="file-save-state"]', 'Saved');
});

it('tells the person Claude is told about their edits while Claude works', function () {
    $thread = filesEditorThread(RunnerState::Busy);
    queueTree($this);
    queueFileRead($this, 'old');

    $page = openFilesPanel($this, $thread);

    $page->assertPresent('[data-testid="editor-working-notice"]')
        ->assertSeeIn('[data-testid="editor-told-line"]', 'Claude is told about your edits');
});

it('shows a conflict banner and a read-only compare after an external change', function () {
    $thread = filesEditorThread();
    queueTree($this);
    queueFileRead($this, 'old');
    queueFileRead($this, 'changed by claude');

    $page = openFilesPanel($this, $thread);

    $page->click('[data-testid="file-editor"] .cm-content')
        ->keys('[data-testid="file-editor"] .cm-content', 'x')
        ->keys('[data-testid="file-editor"] .cm-content', 'ControlOrMeta+s')
        ->assertPresent('[data-testid="editor-conflict-banner"]')
        ->click('[data-testid="conflict-compare-btn"]')
        ->assertPresent('[data-testid="conflict-compare-view"]')
        ->assertMissing('[data-testid="conflict-compare-view"] .cm-content[contenteditable="true"]')
        ->assertMissing('[data-testid="conflict-compare-view"] .cm-merge-revert');
});
