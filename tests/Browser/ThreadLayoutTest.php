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

test('Files opens in Changed mode as a full-width view, the rail steps aside and Open PR stays in the header', function () {
    $thread = layoutThread();

    $page = visit(route('threads.show', $thread))->resize(1440, 900);

    $page->assertVisible('[data-testid="thread-sidebar"]')
        ->assertVisible('[data-testid="thread-header-open-pr"]')
        ->assertMissing('[data-testid="thread-dock-toggle-changes"]')
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'changed')
        ->assertVisible('[data-testid="changes-panel"]')
        ->assertVisible('[data-testid="thread-header-open-pr"]')
        ->assertSeeIn('[data-testid="thread-dock-count-files"]', '1')
        ->assertSeeIn('[data-testid="files-mode-count"]', '1')
        ->assertPresent('[data-testid="changes-diff-split"]')
        ->click('[data-testid="changes-mode-unified"]')
        ->assertMissing('[data-testid="changes-diff-split"]')
        ->assertPresent('[data-testid="changes-review-bar"]')
        ->wait(0.4)
        ->assertNoJavaScriptErrors();

    expect($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').getBoundingClientRect().width'))->toBe(0);
    expect($page->script('document.querySelector(\'[data-testid="files-view"]\').getBoundingClientRect().width > 700'))->toBeTrue();
});

test('the Overview rail animates open and closed, stays out of the tab order while closed, and keeps the conversation steady', function () {
    $thread = layoutThread();

    $page = visit(route('threads.show', $thread))->resize(1440, 900)->wait(0.5);

    expect($page->script('getComputedStyle(document.querySelector(\'[data-testid="thread-sidebar"]\')).transitionDuration'))->toBe('0.2s')
        ->and($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').inert'))->toBeFalse();

    $page->click('[data-testid="thread-rail-toggle"]')->wait(0.4);

    expect($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').getBoundingClientRect().width'))->toBe(0)
        ->and($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').inert'))->toBeTrue()
        ->and($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').getAttribute("aria-hidden")'))->toBe('true');

    $page->click('[data-testid="thread-rail-toggle"]')->wait(0.4);

    expect($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').getBoundingClientRect().width'))->toBe(312)
        ->and($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').inert'))->toBeFalse();
});

test('the Overview rail has no transition when the visitor prefers reduced motion', function () {
    $thread = layoutThread();

    $page = visit(route('threads.show', $thread), ['reducedMotion' => 'reduce'])->resize(1440, 900)->wait(0.5);

    expect($page->script('getComputedStyle(document.querySelector(\'[data-testid="thread-sidebar"]\')).transitionProperty'))->toBe('none');
});

test('the floating Overview rail slides in over the open view below 1200px', function () {
    $thread = layoutThread();

    $page = visit(route('threads.show', $thread))->resize(1100, 800)->wait(0.5);

    $page->click('[data-testid="thread-dock-toggle-files"]')->wait(0.4);
    expect($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').inert'))->toBeTrue()
        ->and($page->script('getComputedStyle(document.querySelector(\'[data-testid="thread-sidebar"]\')).opacity'))->toBe('0');

    $page->click('[data-testid="thread-rail-toggle"]')->wait(0.4);
    expect($page->script('document.querySelector(\'[data-testid="thread-sidebar"]\').inert'))->toBeFalse()
        ->and($page->script('getComputedStyle(document.querySelector(\'[data-testid="thread-sidebar"]\')).position'))->toBe('absolute')
        ->and($page->script('getComputedStyle(document.querySelector(\'[data-testid="thread-sidebar"]\')).opacity'))->toBe('1');
});

test('a changed file collapses, and its header opens the editor in Everything mode', function () {
    $sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandboxes);
    $thread = layoutThread();
    $sandboxes->queueExecResult(Process::result("f\t3\tother.txt\0"));
    $bytes = "<?php\n\$size = 2;\nreturn \$size;\n";
    $sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertPresent('[data-testid="changes-diff-viewer"]')
        ->click('[data-testid="changes-file-header"] button[aria-label="Collapse file"]')
        ->assertMissing('[data-testid="changes-diff-viewer"]')
        ->assertPresent('[data-testid="changes-edit-file"]')
        ->click('[data-testid="changes-edit-file"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'everything')
        ->assertPresent('[data-testid="files-open-tabs"]')
        ->assertSeeIn('[data-testid="files-open-path"]', 'widget.php')
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

test('the header menu keeps only what has no other home and the composer menu offers a sub-thread', function () {
    $thread = layoutThread();

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-menu"]')
        ->assertMissing('[data-testid="thread-menu-add-sub-thread"]')
        ->assertMissing('[data-testid="thread-menu-teleport"]')
        ->assertMissing('[data-testid="thread-menu-share"]')
        ->assertPresent('[data-testid="thread-menu-copy-branch"]')
        ->assertPresent('[data-testid="thread-menu-close"]')
        ->assertPresent('[data-testid="thread-menu-delete"]')
        ->assertNoJavaScriptErrors();

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="composer-plus-menu"]')
        ->click('[data-testid="composer-add-sub-thread"]')
        ->assertVisible('[data-testid="stack-add-dialog"]')
        ->assertNoJavaScriptErrors();
});

test('Everything shows the full tree with a changed badge, View diff returns to Changed, and Edit file comes back', function () {
    $sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandboxes);
    $thread = layoutThread();
    $sandboxes->queueExecResult(Process::result("f\t3\tsrc/widget.php\0f\t3\tother.txt\0"));
    $bytes = "<?php\n\$size = 2;\nreturn \$size;\n";
    $sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'changed')
        ->click('[data-testid="files-mode-everything"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'everything')
        ->assertPresent('[data-path="other.txt"]')
        ->assertPresent('[data-testid="file-changed-src/widget.php"]')
        ->click('[data-path="src/widget.php"]')
        ->click('[data-testid="files-view-diff"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'changed')
        ->assertVisible('[data-testid="changes-panel"]')
        ->click('[data-testid="changes-edit-file"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'everything')
        ->assertSeeIn('[data-testid="files-open-path"]', 'widget.php')
        ->click('[data-testid="files-mode-changed"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'changed')
        ->assertNoJavaScriptErrors();
});

test('Files opens in Everything mode when the thread has no changes', function () {
    $sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandboxes);
    $thread = layoutThread();
    $thread->diff()->delete();
    $sandboxes->queueExecResult(Process::result("f\t3\tother.txt\0"));

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->click('[data-testid="thread-dock-toggle-files"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'everything')
        ->assertPresent('[data-testid="files-panel"]')
        ->click('[data-testid="files-mode-changed"]')
        ->assertPresent('[data-testid="changes-empty"]')
        ->assertNoJavaScriptErrors();
});

test('the phone shows five tabs and never scrolls sideways', function () {
    $thread = layoutThread();

    $page = visit(route('threads.show', $thread))->on()->mobile()->resize(390, 844)->assertPresent('[data-testid="thread-phone-tab-overview"]')->wait(1);

    expect($page->script('window.innerWidth'))->toBe(390);
    expect($page->script('[...document.querySelectorAll(\'[data-testid^="thread-phone-tab-"]\')].map((tab) => tab.textContent.trim()).filter((text) => text !== "").join(",")'))
        ->toBe('Chat,Preview,Files1,Terminal,Overview');

    foreach (['files', 'terminal', 'overview', 'preview', 'chat'] as $tab) {
        $page->click("[data-testid=\"thread-phone-tab-{$tab}\"]")->wait(0.5);
        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue("{$tab} scrolls sideways");
    }

    $page->click('[data-testid="thread-phone-tab-files"]')
        ->assertVisible('[data-testid="files-mode-switch"]')
        ->assertVisible('[data-testid="changes-panel"]')
        ->assertMissing('[data-testid="changes-diff-split"]')
        ->assertVisible('[data-testid="open-pull-request"]')
        ->click('[data-testid="files-mode-everything"]')
        ->assertVisible('[data-testid="files-panel"]')
        ->click('[data-testid="files-mode-changed"]')
        ->assertVisible('[data-testid="changes-panel"]')
        ->click('[data-testid="thread-phone-tab-overview"]')
        ->assertVisible('[data-testid="thread-sidebar"]')
        ->assertNoJavaScriptErrors();
});

test('the Overview Review changes link opens Files in Changed mode', function () {
    $thread = layoutThread();

    visit(route('threads.show', $thread))->resize(1440, 900)
        ->assertVisible('[data-testid="environment-review-changes"]')
        ->click('[data-testid="environment-review-changes"]')
        ->assertAttribute('[data-testid="files-view"]', 'data-mode', 'changed')
        ->assertVisible('[data-testid="changes-panel"]')
        ->assertNoJavaScriptErrors();
});
