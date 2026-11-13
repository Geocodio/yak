<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
});

function layoutThread(array $attributes = []): Thread
{
    $thread = Thread::factory()->private(test()->owner)->create([
        'repo' => 'acme/widgets',
        'branch' => 'yak/widgets',
        'sandbox_state' => ThreadSandboxState::Running,
        'runner_state' => RunnerState::Idle,
        ...$attributes,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 1, 'additions' => 1, 'deletions' => 1]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create([
        'path' => 'src/widget.php',
        'additions' => 1,
        'deletions' => 1,
        'patch' => "@@ -1,3 +1,3 @@\n <?php\n-\$size = 1;\n+\$size = 2;\n return \$size;\n",
    ]);

    return $thread;
}

test('Changes is a full-width view, the rail steps aside and Open PR stays in the header', function () {
    $thread = layoutThread();

    $page = visit(route('threads.show', $thread))->resize(1440, 900);

    $page->assertVisible('[data-testid="thread-sidebar"]')
        ->assertVisible('[data-testid="thread-header-open-pr"]')
        ->click('[data-testid="thread-dock-toggle-changes"]')
        ->assertVisible('[data-testid="changes-panel"]')
        ->assertVisible('[data-testid="thread-header-open-pr"]')
        ->assertSeeIn('[data-testid="thread-dock-count-changes"]', '1')
        ->assertPresent('[data-testid="changes-diff-split"]')
        ->click('[data-testid="changes-mode-unified"]')
        ->assertMissing('[data-testid="changes-diff-split"]')
        ->assertNoJavaScriptErrors();

    expect($page->script('getComputedStyle(document.querySelector(\'[data-testid="thread-sidebar"]\')).display'))->toBe('none');
    expect($page->script('document.querySelector(\'[data-testid="changes-panel"]\').getBoundingClientRect().width > 700'))->toBeTrue();
});

test('a changed file collapses, and its header opens it in Files', function () {
    $thread = layoutThread();

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-dock-toggle-changes"]')
        ->assertPresent('[data-testid="changes-diff-viewer"]')
        ->click('[data-testid="changes-file-header"] button[aria-label="Collapse file"]')
        ->assertMissing('[data-testid="changes-diff-viewer"]')
        ->assertPresent('[data-testid="changes-open-in-files"]')
        ->assertNoJavaScriptErrors();
});

test('the Overview shows a preview link that opens in a new tab', function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $thread = layoutThread(['preview_hostname' => 'widgets.yak.example.com']);

    $page = visit(route('threads.show', $thread))->resize(1440, 900);

    $page->assertVisible('[data-testid="environment-preview-link"]')
        ->assertAttribute('[data-testid="environment-preview-link"]', 'href', 'https://widgets.yak.example.com')
        ->assertAttribute('[data-testid="environment-preview-link"]', 'target', '_blank')
        ->assertPresent('[data-testid="environment-preview-copy"]')
        ->assertAttribute('[data-testid="environment-preview-open"]', 'target', '_blank')
        ->assertNoJavaScriptErrors();
});

test('the header menu and the composer menu both offer a sub-thread', function () {
    $thread = layoutThread();

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-menu"]')
        ->click('[data-testid="thread-menu-add-sub-thread"]')
        ->assertVisible('[data-testid="stack-add-dialog"]')
        ->assertNoJavaScriptErrors();
});

test('the Files Changed filter lists changed files, and View diff opens Changes', function () {
    $sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandboxes);
    $thread = layoutThread();
    $sandboxes->queueExecResult(Process::result("f\t3\tother.txt\0"));
    $bytes = "<?php\n\$size = 2;\nreturn \$size;\n";
    $sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertSeeIn('[data-testid="files-changed-count"]', '1')
        ->click('[data-testid="files-filter-changed"]')
        ->assertPresent('[data-testid="file-changed-src/widget.php"]')
        ->assertMissing('[data-path="other.txt"]')
        ->click('[data-testid="files-tree-item"]')
        ->click('[data-testid="files-view-diff"]')
        ->assertVisible('[data-testid="changes-panel"]')
        ->assertNoJavaScriptErrors();
});

test('the phone shows five tabs and never scrolls sideways', function () {
    $thread = layoutThread();

    $page = visit(route('threads.show', $thread))->on()->mobile()->resize(390, 844)->assertPresent('[data-testid="thread-phone-tab-overview"]')->wait(1);

    expect($page->script('window.innerWidth'))->toBe(390);
    expect($page->script('[...document.querySelectorAll(\'[data-testid^="thread-phone-tab-"]\')].map((tab) => tab.textContent.trim()).filter((text) => text !== "").join(",")'))
        ->toBe('Chat,Preview,Changes1,Terminal,Overview');

    foreach (['changes', 'terminal', 'overview', 'preview', 'chat'] as $tab) {
        $page->click("[data-testid=\"thread-phone-tab-{$tab}\"]")->wait(0.5);
        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue("{$tab} scrolls sideways");
    }

    $page->click('[data-testid="thread-phone-tab-changes"]')
        ->assertVisible('[data-testid="changes-panel"]')
        ->assertMissing('[data-testid="changes-diff-split"]')
        ->assertVisible('[data-testid="open-pull-request"]')
        ->click('[data-testid="thread-phone-tab-overview"]')
        ->assertVisible('[data-testid="thread-sidebar"]')
        ->assertNoJavaScriptErrors();
});
