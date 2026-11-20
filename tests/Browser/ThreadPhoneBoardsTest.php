<?php

use App\Enums\RunnerState;
use App\Enums\StopKind;
use App\Enums\ThreadStatus;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;

require_once __DIR__ . '/../Support/NewPageFixtures.php';

function phonePage(Thread $thread, int $width = 390, int $height = 844): mixed
{
    $page = visit(route('threads.show', $thread));
    $page->resize($width, $height)->assertVisible('[data-testid="thread-composer"]');

    return $page;
}

function withPullRequest(Thread $thread, ?string $state): void
{
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'pull_request_url' => 'https://github.com/geocodio/geocodio/pull/3105', 'pull_request_number' => 3105, 'pull_request_state' => $state]);
}

beforeEach(function () {
    $this->viewer = User::factory()->withGitHub(4242, 'mhansen')->create(['name' => 'Mathias Hansen']);
    $this->actingAs($this->viewer);
});

it('names the pull request state in the phone header pill', function (?string $state, string $label) {
    [$thread] = seedConversationThread();
    withPullRequest($thread, $state);

    $page = phonePage($thread);

    expect(settled($page, "document.querySelector('[data-testid=\"thread-phone-pill\"]')", "document.querySelector('[data-testid=\"thread-phone-pill\"]')?.textContent"))->toBe($label);
})->with([[null, '#3105 Open'], ['merged', '#3105 Merged'], ['closed', '#3105 Closed']]);

it('says what the thread is waiting for or why it stopped in the pill', function () {
    $pill = fn (Thread $thread) => settled(phonePage($thread), "document.querySelector('[data-testid=\"thread-phone-pill\"]')", "document.querySelector('[data-testid=\"thread-phone-pill\"]')?.textContent");

    expect($pill(seedCoOwnerCardThread($this->viewer)))->toBe('PR held for a co-owner');
    expect($pill(seedStopCardThread()))->toBe('Stopped');

    [$failed, $task] = seedConversationThread([], ['status' => 'failed', 'pr_url' => null, 'pr_number' => null, 'pr_opened_at' => null, 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'CI failed twice.']);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'CI result received', 'metadata' => ['passed' => false], 'created_at' => now()]);
    expect($pill($failed))->toBe('Stopped, CI failed');

    [$closed] = seedConversationThread(['status' => ThreadStatus::Closed, 'closed_at' => now()]);
    withPullRequest($closed, 'merged');
    expect($pill($closed))->toBe('Closed');
});

it('never prints "unknown" for a thread without a repository', function () {
    $thread = Thread::factory()->create(['repo' => 'unknown', 'created_by_user_id' => $this->viewer->id, 'owner_user_id' => $this->viewer->id]);

    $page = phonePage($thread);

    expect($page->script('document.querySelector(\'[data-testid="thread-header-phone-meta"]\').innerText'))->not->toContain('unknown');
});

it('keeps the pill whole and truncates the source and branch first at 375 px', function () {
    [$thread] = seedConversationThread(['source_reference' => 'a-deliberately-long-channel-name-for-the-slack-source-line']);
    withPullRequest($thread, null);

    $page = phonePage(seedCoOwnerCardThread($this->viewer), 375, 667);
    $measure = settled($page, 'document.querySelector(\'[data-testid="thread-phone-pill"]\')', '(() => { const meta = document.querySelector(\'[data-testid="thread-header-phone-meta"]\').getBoundingClientRect(); const pill = document.querySelector(\'[data-testid="thread-phone-pill"]\'); const rect = pill.getBoundingClientRect(); const branch = document.querySelector(\'[data-testid="thread-phone-branch"] code\'); return { pillInside: rect.right <= meta.right + 0.5 && rect.left >= meta.left, pillWhole: pill.scrollWidth <= pill.clientWidth, branchShown: branch !== null && getComputedStyle(branch).display !== \'none\' }; })()');

    expect($measure['pillInside'])->toBeTrue()->and($measure['pillWhole'])->toBeTrue();

    $page = phonePage($thread, 375, 667);
    $long = settled($page, 'document.querySelector(\'[data-testid="thread-phone-source"]\')', '(() => { const meta = document.querySelector(\'[data-testid="thread-header-phone-meta"]\').getBoundingClientRect(); const source = document.querySelector(\'[data-testid="thread-phone-source"]\'); const pill = document.querySelector(\'[data-testid="thread-phone-pill"]\'); const branch = document.querySelector(\'[data-testid="thread-phone-branch"] code\'); return { sourceTruncated: source.scrollWidth > source.clientWidth || getComputedStyle(source).textOverflow === \'ellipsis\', pillInside: pill === null || pill.getBoundingClientRect().right <= meta.right + 0.5, branchShown: branch !== null && getComputedStyle(branch).display !== \'none\' }; })()');

    expect($long['sourceTruncated'])->toBeTrue()->and($long['pillInside'])->toBeTrue()->and($long['branchShown'])->toBeFalse();
});

it('shows the progress steps near the current step, and hides the row when finished or waiting for a person', function () {
    $visibleSteps = fn (Thread $thread) => settled(phonePage($thread), "document.querySelector('[data-testid=\"thread-composer\"]')", '(() => { const row = document.querySelector(\'[data-testid="thread-progress-row"]\'); return row === null || row.getClientRects().length === 0 ? null : [...row.querySelectorAll(\'li\')].filter((step) => step.getClientRects().length > 0).map((step) => step.dataset.state + \':\' + step.firstChild.nextSibling?.textContent?.trim()); })()');

    [$failed, $task] = seedConversationThread([], ['status' => 'failed', 'pr_url' => null, 'pr_number' => null, 'pr_opened_at' => null, 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'CI failed twice.']);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'CI result received', 'metadata' => ['passed' => false], 'created_at' => now()]);
    $steps = $visibleSteps($failed);

    expect(array_map(fn ($step) => explode(':', $step)[1], $steps))->toBe(['Working', 'Pushed', 'CI failed', 'Pull request'])
        ->and($steps[2])->toStartWith('failed:');

    foreach ([[390, 844], [375, 667]] as [$width, $height]) {
        $fits = settled(phonePage($failed, $width, $height), "document.querySelector('[data-testid=\"thread-progress-row\"]')", '(() => { const row = document.querySelector(\'[data-testid="thread-progress-row"]\'); const bounds = row.getBoundingClientRect(); const last = [...row.querySelectorAll(\'li\')].filter((step) => step.getClientRects().length > 0).at(-1).getBoundingClientRect(); return { lastInside: last.right <= bounds.right + 0.5, noScroll: row.scrollWidth <= row.clientWidth + 1 }; })()');

        expect($fits)->toBe(['lastInside' => true, 'noScroll' => true]);
    }

    [$running] = seedConversationThread([], ['status' => 'running', 'pr_url' => null, 'pr_number' => null, 'pr_opened_at' => null]);
    $running->forceFill(['runner_state' => RunnerState::Busy])->save();
    $first = $visibleSteps($running);
    expect($first)->not->toBeNull()->and(array_map(fn ($step) => explode(':', $step)[1], $first))->toContain('Working')->not->toContain('Done');

    expect($visibleSteps(seedConversationThread()[0]))->toBeNull()
        ->and($visibleSteps(seedCoOwnerCardThread($this->viewer)))->toBeNull();
});

it('folds a long request on a phone and unfolds it with Show the full request', function () {
    [$thread] = seedConversationThread([], ['description' => 'Delete the importer. ' . str_repeat('This sentence makes the request long enough to fold. ', 12)]);

    $page = phonePage($thread);
    $page->assertVisible('[data-testid="thread-message-expand"]');
    $before = $page->script('(() => { const button = document.querySelector(\'[data-testid="thread-message-expand"]\'); return { text: button.textContent, height: Math.round(button.getBoundingClientRect().height), body: Math.round(button.parentElement.getBoundingClientRect().height) }; })()');

    expect($before['text'])->toBe('Show the full request')->and($before['height'])->toBeGreaterThanOrEqual(44);

    $page->click('[data-testid="thread-message-expand"]')->assertMissing('[data-testid="thread-message-expand"]');
    $after = $page->script('Math.round(document.querySelector(\'[data-testid="thread-message"]\').getBoundingClientRect().height)');

    expect($after)->toBeGreaterThan($before['body']);
});

it('does not offer the fold on a desktop width', function () {
    [$thread] = seedConversationThread([], ['description' => 'Delete the importer. ' . str_repeat('This sentence makes the request long enough to fold. ', 12)]);

    $page = visit(route('threads.show', $thread))->resize(1024, 800)->assertVisible('[data-testid="thread-message"]');

    expect($page->script('getComputedStyle(document.querySelector(\'[data-testid="thread-message-expand"]\')).display'))->toBe('none');
});

it('stacks the co-owner card buttons Approve, Request changes, Reject at 44 px on a phone', function () {
    $page = phonePage(seedCoOwnerCardThread($this->viewer));
    $buttons = settled($page, 'document.querySelector(\'[data-testid="co-owner-approve"]\')', '[...document.querySelectorAll(\'[data-testid="card-co-owner"] button\')].filter((button) => [\'co-owner-approve\', \'co-owner-request-changes\', \'co-owner-reject\'].includes(button.dataset.testid)).map((button) => { const rect = button.getBoundingClientRect(); return [button.dataset.testid, Math.round(rect.height), Math.round(rect.width), Math.round(rect.top)]; })');

    expect(array_column($buttons, 0))->toBe(['co-owner-approve', 'co-owner-request-changes', 'co-owner-reject'])
        ->and(array_column($buttons, 1))->each->toBeGreaterThanOrEqual(44)
        ->and($buttons[1][3])->toBeGreaterThan($buttons[0][3])->and($buttons[2][3])->toBeGreaterThan($buttons[1][3])
        ->and($buttons[0][2])->toBe($buttons[2][2]);
});

it('stacks the stop card buttons Retry, Move to another repository, Show the failing step at 44 px', function () {
    $page = phonePage(seedStopCardThread());
    $buttons = settled($page, 'document.querySelector(\'[data-testid="stop-retry"]\')', '[...document.querySelectorAll(\'[data-testid="card-stop"] button\')].map((button) => [button.dataset.testid, Math.round(button.getBoundingClientRect().height), Math.round(button.getBoundingClientRect().top)])');
    $buttons = array_values(array_filter($buttons, fn ($button) => str_starts_with($button[0], 'stop-')));

    expect(array_column($buttons, 0))->toBe(['stop-retry', 'stop-move', 'stop-show-log'])
        ->and(array_column($buttons, 1))->each->toBeGreaterThanOrEqual(44)
        ->and($buttons[1][2])->toBeGreaterThan($buttons[0][2]);
});

it('keeps the card in the scroller on a phone and above the composer on a wide screen, and keeps what was typed when the viewport changes', function () {
    $thread = seedCoOwnerCardThread($this->viewer);
    $isInScroller = 'document.querySelector(\'[data-testid="thread-conversation-scroller"]\').contains(document.querySelector(\'[data-testid="card-co-owner"]\'))';

    $page = visit(route('threads.show', $thread));
    $page->resize(844, 390)->assertVisible('[data-testid="co-owner-request-changes"]')->click('[data-testid="co-owner-request-changes"]')->fill('[data-testid="co-owner-feedback-text"]', 'Please also cover the proration.');
    expect(settled($page, "!({$isInScroller})", "!({$isInScroller})"))->toBeTrue();

    $page->resize(390, 844);
    expect(settled($page, $isInScroller, $isInScroller))->toBeTrue()
        ->and($page->script('document.querySelector(\'[data-testid="co-owner-feedback-text"]\').value'))->toBe('Please also cover the proration.');

    $page->resize(844, 390);
    expect(settled($page, "!({$isInScroller})", "!({$isInScroller})"))->toBeTrue()
        ->and($page->script('document.querySelector(\'[data-testid="co-owner-feedback-text"]\').value'))->toBe('Please also cover the proration.');
});

it('drops what was typed in a card when the reader leaves the thread, so it comes back clean', function () {
    $thread = seedCoOwnerCardThread($this->viewer);
    $other = seedStopCardThread();
    foreach ([$thread, $other] as $listed) {
        $listed->forceFill(['owner_user_id' => $this->viewer->id, 'created_by_user_id' => $this->viewer->id])->save();
    }
    $link = fn (Thread $target) => "document.querySelector('a[href\$=\"/threads/{$target->public_id}\"]')";

    $page = visit(route('threads.show', $thread))->resize(1280, 800);
    $page->assertVisible('[data-testid="co-owner-request-changes"]')->click('[data-testid="co-owner-request-changes"]')->fill('[data-testid="co-owner-feedback-text"]', 'Please also cover the proration.');

    expect(settled($page, $link($other), "({$link($other)}.click(), true)"))->toBeTrue();
    $page->assertVisible('[data-testid="card-stop"]');
    expect(settled($page, $link($thread), "({$link($thread)}.click(), true)"))->toBeTrue();
    $page->assertVisible('[data-testid="co-owner-request-changes"]')->assertMissing('[data-testid="co-owner-feedback-text"]');
});

it('scrolls a card that grows into view when the reader is at the bottom, and offers the jump when they scrolled up', function () {
    $thread = seedCoOwnerCardThread($this->viewer);
    $page = phonePage($thread, 375, 667);
    $page->assertVisible('[data-testid="co-owner-request-changes"]')->click('[data-testid="co-owner-request-changes"]')->assertVisible('[data-testid="co-owner-feedback-text"]');

    $isVisible = '(() => { const box = document.querySelector(\'[data-testid="thread-conversation-scroller"]\').getBoundingClientRect(); const field = document.querySelector(\'[data-testid="co-owner-feedback-text"]\').getBoundingClientRect(); return field.bottom <= box.bottom + 1 && field.top >= box.top - 1; })()';
    expect(settled($page, $isVisible, $isVisible))->toBeTrue();

    $page = phonePage(seedCoOwnerCardThread($this->viewer), 375, 667);
    $page->script('(async () => { const box = document.querySelector(\'[data-testid="thread-conversation-scroller"]\'); box.scrollTop = 0; await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))); document.querySelector(\'[data-testid="co-owner-request-changes"]\').click(); })()');

    $page->assertVisible('[data-testid="thread-jump-to-newest"]');
});

it('gives every phone control a 44 px target', function () {
    $page = phonePage(seedStopCardThread());
    $page->click('[data-testid="thread-composer-input"]');
    $sizes = settled($page, 'document.querySelector(\'[data-testid="thread-composer-tools"]\')?.getClientRects().length > 0', '(() => { const heights = (selector) => [...document.querySelectorAll(selector)].filter((element) => element.getClientRects().length > 0).map((element) => Math.round(element.getBoundingClientRect().height)); const widths = (selector) => [...document.querySelectorAll(selector)].filter((element) => element.getClientRects().length > 0).map((element) => Math.round(element.getBoundingClientRect().width)); return { tabs: heights(\'[data-testid^="thread-phone-tab-"]:not([data-testid$="dot"])\'), tools: heights(\'[data-testid="thread-composer-tools"] button\'), toolWidths: widths(\'[data-testid="thread-composer-tools"] button\'), toggle: [heights(\'[data-testid="mobile-nav-toggle"]\')[0], widths(\'[data-testid="mobile-nav-toggle"]\')[0]], send: heights(\'[data-testid="thread-send"]\') }; })()');

    expect($sizes['tabs'])->not->toBeEmpty()->each->toBeGreaterThanOrEqual(44)
        ->and($sizes['tools'])->not->toBeEmpty()->each->toBeGreaterThanOrEqual(44)
        ->and($sizes['toolWidths'])->each->toBeGreaterThanOrEqual(44)
        ->and($sizes['toggle'])->each->toBeGreaterThanOrEqual(44)
        ->and($sizes['send'])->each->toBeGreaterThanOrEqual(44);

    $page->click('[data-testid="mobile-nav-toggle"]')->assertVisible('[data-testid="mobile-nav"]');
    $switcher = settled($page, 'document.querySelector(\'[data-testid="mobile-section-switcher"] button\')', '[...document.querySelectorAll(\'[data-testid="mobile-section-switcher"] button\')].map((button) => Math.round(button.getBoundingClientRect().height))');

    expect($switcher)->toHaveCount(4)->each->toBeGreaterThanOrEqual(44);
});

it('tabs from the field to Send first, then through the tools, in the order they are drawn', function () {
    $page = phonePage(seedStopCardThread());
    $page->keys('[data-testid="thread-composer-input"]', 'x');
    $order = settled($page, 'document.querySelector(\'[data-testid="thread-send"]\') && !document.querySelector(\'[data-testid="thread-send"]\').disabled', '1');

    $active = function (string $key) use ($page): string {
        $page->keys('[data-testid="thread-composer-input"]', 'Tab');

        return $page->script('(document.activeElement.closest(\'[data-testid]\') ?? document.activeElement).dataset.testid ?? document.activeElement.tagName');
    };

    expect($order)->toBe(1)
        ->and($active('first'))->toBe('thread-send');

    $visual = $page->script('(() => { const rect = (selector) => document.querySelector(selector).getBoundingClientRect(); return { sendTop: rect(\'[data-testid="thread-send"]\').top, toolsTop: rect(\'[data-testid="thread-composer-tools"]\').top, inputTop: rect(\'[data-testid="thread-composer-input"]\').top }; })()');
    $dom = $page->script('(() => { const nodes = [...document.querySelectorAll(\'[data-testid="thread-composer-input"], [data-testid="thread-send"], [data-testid="thread-composer-tools"]\')]; return nodes.map((node) => node.dataset.testid); })()');

    expect($dom)->toBe(['thread-composer-input', 'thread-send', 'thread-composer-tools'])
        ->and($visual['sendTop'])->toBeLessThan($visual['toolsTop']);
});

it('keeps the tab bar from 640 px up and the way out of a thread on a phone', function () {
    $thread = seedStopCardThread();

    $page = visit(route('threads.show', $thread))->resize(700, 900)->assertVisible('[data-testid="mobile-tab-bar"]');
    $page->resize(390, 844)->assertMissing('[data-testid="mobile-tab-bar"]:visible')
        ->click('[data-testid="mobile-nav-toggle"]')
        ->assertVisible('[data-testid="mobile-nav"]')
        ->assertSeeIn('[data-testid="mobile-nav"]', 'New thread')
        ->assertSeeIn('[data-testid="mobile-section-switcher"]', 'Operations')
        ->assertSeeIn('[data-testid="mobile-section-switcher"]', 'Settings')
        ->click('[data-testid="mobile-section-operations"]')
        ->assertSeeIn('[data-testid="mobile-nav"]', 'PR reviews')
        ->assertSeeIn('[data-testid="mobile-nav"] nav', 'Repositories')
        ->assertNoJavaScriptErrors();
});

it('keeps the submit button and the composer reachable with a keyboard-sized viewport over the question form', function () {
    $thread = Thread::factory()->create(['created_by_user_id' => $this->viewer->id, 'owner_user_id' => $this->viewer->id]);
    $task = YakTask::factory()->withClarificationQuestions([
        ['id' => 'scope', 'header' => 'Scope', 'question' => 'Which scope?', 'options' => [['label' => 'Small', 'description' => 'Just pay as you go'], ['label' => 'Large', 'description' => 'Everyone']], 'multi_select' => false],
    ])->create(['thread_id' => $thread->id, 'started_by_user_id' => $this->viewer->id]);

    $page = phonePage($thread, 390, 400);
    $page->assertVisible('[data-testid="questions-form"]');

    $state = settled($page, 'document.querySelector(\'[data-testid="questions-submit"]\')', '(async () => { const submit = document.querySelector(\'[data-testid="questions-submit"]\'); submit.scrollIntoView({ block: \'center\' }); await new Promise((resolve) => setTimeout(resolve, 200)); const rect = submit.getBoundingClientRect(); const top = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2); const composer = document.querySelector(\'[data-testid="thread-composer"]\').getBoundingClientRect(); return { submitReachable: submit.contains(top), composerInside: composer.bottom <= window.innerHeight + 1, submitAboveComposer: rect.bottom <= composer.top + 1, pageScrolls: document.documentElement.scrollHeight > window.innerHeight + 1, scrollHeight: document.documentElement.scrollHeight, innerHeight: window.innerHeight, connectionStrip: document.querySelector(\'[data-testid="thread-connection-strip"]\')?.dataset.kind ?? null }; })()');

    expect($state)->toMatchArray(['submitReachable' => true, 'composerInside' => true, 'submitAboveComposer' => true, 'pageScrolls' => false], json_encode($state));
});

it('keeps the 768 px layout usable: tabs and the tab bar', function () {
    $page = visit(route('threads.show', seedStopCardThread()))->resize(768, 1024)->assertVisible('[data-testid="thread-phone-tabs"]')->assertVisible('[data-testid="mobile-tab-bar"]');

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(false, 'phone-768');
});
