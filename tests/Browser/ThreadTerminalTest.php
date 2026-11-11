<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    config()->set('yak.ssh.internal_token', 'secret-token');
});

/**
 * A running sandbox keeps the session endpoint off Incus: the browser server boots its own app container without the test fakes.
 */
function terminalRunningThread(): Thread
{
    return Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_state_changed_at' => now()]);
}

/**
 * Replaces WebSocket with a recording fake that echoes every input frame. The bridge itself is covered by the Go tests;
 * the session endpoint behind it is the real route.
 */
function installTerminalSocketStub($page): void
{
    // The page must have loaded before the stub goes in, or a later navigation replaces it.
    $page->assertPresent('[data-testid="thread-header"]');
    $page->script(<<<'JS'
(() => {
    const record = (window.__ws = { sent: [], control: [], urls: [], url: null, sockets: [], push: null, close: null });
    const decoder = new TextDecoder();
    class FakeSocket {
        static OPEN = 1;
        constructor(url) {
            this.url = url;
            this.readyState = 0;
            this.binaryType = 'blob';
            record.url = url;
            record.urls.push(url);
            record.sockets.push(this);
            setTimeout(() => {
                this.readyState = 1;
                this.onopen && this.onopen({});
            }, 0);
        }
        send(data) {
            if (typeof data === 'string') {
                record.control.push(data);

                return;
            }
            record.sent.push(decoder.decode(data));
            const echo = new Uint8Array(data).slice().buffer;
            setTimeout(() => this.onmessage && this.onmessage({ data: echo }), 0);
        }
        close(code = 1000) {
            if (this.readyState === 3) {
                return;
            }
            this.readyState = 3;
            this.onclose && this.onclose({ code });
        }
    }
    const latest = () => record.sockets[record.sockets.length - 1];
    record.push = (text) => latest().onmessage({ data: new TextEncoder().encode(text).buffer });
    record.close = (code) => latest().close(code);
    const RealWebSocket = window.WebSocket;
    // Only the terminal socket is faked; the page's realtime socket keeps using the real class.
    window.WebSocket = new Proxy(RealWebSocket, {
        construct: (target, args) => (String(args[0]).includes('/terminal/ws') ? new FakeSocket(...args) : new target(...args)),
    });
    // Keep the automatic reconnect from racing the assertions; the Reconnect button is the path under test.
    window.__yakTerminalBackoff = [600000];
})();
JS);
}

function allowTerminalOrigin($page): void
{
    $parts = parse_url($page->url());
    config()->set('yak.ssh.terminal_allowed_origins', [$parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')]);
}

function waitForTerminal($page, string $condition): void
{
    $found = $page->script(<<<JS
new Promise((resolve) => {
    const started = Date.now();
    const tick = () => (({$condition}) ? resolve(true) : Date.now() - started > 8000 ? resolve(false) : setTimeout(tick, 50));
    tick();
})
JS);

    expect($found)->toBeTrue("Timed out waiting for: {$condition}");
}

function openTerminalTab($page, bool $isPhone = false): void
{
    allowTerminalOrigin($page);
    $page->click($isPhone ? '[data-testid="thread-phone-tab-terminal"]' : '[data-testid="thread-dock-toggle-terminal"]');
}

function statusIs(string $state): string
{
    return "document.querySelector('[data-testid=\"terminal-status\"]')?.dataset.state === '{$state}'";
}

test('the terminal connects with the token from the session endpoint', function () {
    $thread = terminalRunningThread();
    $page = visit(route('threads.show', $thread));
    installTerminalSocketStub($page);

    openTerminalTab($page);

    waitForTerminal($page, statusIs('connected'));
    $page->assertSeeIn('[data-testid="terminal-status"]', 'Connected');
    expect($page->script('window.__ws.url'))->toContain('/terminal/ws?token=')
        ->and($page->script('document.querySelector(\'[data-testid="terminal-footer"]\').textContent'))->toContain('yak ssh ' . $thread->name)
        ->and($page->script('document.querySelector(\'[data-testid="terminal-footer"]\').textContent'))->toContain('Closing this tab keeps your shell for 30 minutes.')
        ->toContain('Terminal docs');
    $page->assertNoJavaScriptErrors();
});

test('typed input reaches the bridge and its echo is drawn', function () {
    $page = visit(route('threads.show', terminalRunningThread()));
    installTerminalSocketStub($page);
    openTerminalTab($page);
    waitForTerminal($page, statusIs('connected'));

    $page->keys('.xterm-helper-textarea', ['l', 's']);

    waitForTerminal($page, "window.__ws.sent.join('') === 'ls'");
    waitForTerminal($page, "document.querySelector('.xterm-rows').textContent.includes('ls')");
    $page->assertNoJavaScriptErrors();
});

test('a dropped connection shows lost state and reconnects with a new token', function () {
    $page = visit(route('threads.show', terminalRunningThread()));
    installTerminalSocketStub($page);
    openTerminalTab($page);
    waitForTerminal($page, statusIs('connected'));

    $page->script('window.__ws.close(1006)');
    waitForTerminal($page, statusIs('lost'));
    $page->assertSeeIn('[data-testid="terminal-status"]', 'Disconnected');
    $page->assertSeeIn('[data-testid="terminal-lost"]', 'The terminal lost its connection');
    $page->assertSeeIn('[data-testid="terminal-lost"]', 'Your shell is still running. Anything you typed while disconnected was not sent.');
    $page->assertPresent('.xterm');
    expect($page->script('document.querySelector(\'[data-testid="terminal-lost"]\').classList.contains("absolute")'))->toBeFalse();

    $page->click('[data-testid="terminal-reconnect"]');

    waitForTerminal($page, statusIs('connected'));
    expect($page->script('window.__ws.urls.length'))->toBe(2)
        ->and($page->script('window.__ws.urls[0] !== window.__ws.urls[1]'))->toBeTrue();
});

test('a 4403 close shows the refused state and does not reconnect', function () {
    $page = visit(route('threads.show', terminalRunningThread()));
    installTerminalSocketStub($page);
    $page->script('window.__yakTerminalBackoff = [50]');
    openTerminalTab($page);
    waitForTerminal($page, statusIs('connected'));

    $page->script('window.__ws.close(4403)');

    waitForTerminal($page, statusIs('denied'));
    $page->assertPresent('[data-testid="terminal-denied"]')->wait(0.5);
    expect($page->script('window.__ws.urls.length'))->toBe(1);
});

test('the phone key row sends control codes and arrows', function () {
    $page = visit(route('threads.show', terminalRunningThread()))->on()->mobile()->assertPresent('[data-testid="thread-header"]');
    installTerminalSocketStub($page);
    openTerminalTab($page, true);
    waitForTerminal($page, statusIs('connected'));

    $page->assertPresent('[data-testid="terminal-keyrow"]');
    $page->click('[data-testid="terminal-key-ctrl"]');
    expect($page->script('document.querySelector(\'[data-testid="terminal-key-ctrl"]\').getAttribute("aria-pressed")'))->toBe('true');

    $page->keys('.xterm-helper-textarea', ['c']);
    waitForTerminal($page, "window.__ws.sent.includes('\\x03')");
    expect($page->script('document.querySelector(\'[data-testid="terminal-key-ctrl"]\').getAttribute("aria-pressed")'))->toBe('false');

    $page->click('[data-testid="terminal-key-up"]');
    waitForTerminal($page, "window.__ws.sent.includes('\\x1b[A')");
    $page->assertNoJavaScriptErrors();
});

test('the key row is absent at desktop width', function () {
    $page = visit(route('threads.show', terminalRunningThread()));
    installTerminalSocketStub($page);
    openTerminalTab($page);
    waitForTerminal($page, statusIs('connected'));

    $page->assertMissing('[data-testid="terminal-keyrow"]');
});

test('the phone shows the working strip only while Claude is busy', function () {
    $thread = terminalRunningThread();
    $thread->update(['runner_state' => RunnerState::Busy]);
    $page = visit(route('threads.show', $thread))->on()->mobile()->assertPresent('[data-testid="thread-header"]');
    installTerminalSocketStub($page);
    openTerminalTab($page, true);
    waitForTerminal($page, statusIs('connected'));

    expect($page->script('document.querySelectorAll(\'[data-testid="claude-working-strip"]\').length'))->toBe(1);
    $page->assertMissing('[data-testid="terminal-working"]');
});

test('the phone Terminal tab switches between the shell and the files browser', function () {
    $page = visit(route('threads.show', terminalRunningThread()))->on()->mobile()->assertPresent('[data-testid="thread-header"]');
    openTerminalTab($page, true);

    $page->assertVisible('[data-testid="phone-terminal-switch"]')
        ->assertPresent('[data-testid="terminal-panel"]')
        ->click('[data-testid="phone-terminal-mode-files"]')
        ->assertPresent('[data-testid="files-panel"]')
        ->click('[data-testid="phone-terminal-mode-shell"]')
        ->assertPresent('[data-testid="terminal-panel"]')
        ->assertNoJavaScriptErrors();
});

test('the working strip is absent at desktop width', function () {
    $thread = terminalRunningThread();
    $thread->update(['runner_state' => RunnerState::Busy]);
    $page = visit(route('threads.show', $thread));
    installTerminalSocketStub($page);
    openTerminalTab($page);
    waitForTerminal($page, statusIs('connected'));

    $page->assertMissing('[data-testid="claude-working-strip"]');
});
