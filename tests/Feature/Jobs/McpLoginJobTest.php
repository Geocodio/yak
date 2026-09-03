<?php

use App\ClaudeCli;
use App\Jobs\McpLoginJob;
use App\Services\InteractiveProcess;
use App\Services\InteractiveProcessFactory;
use App\Support\McpLoginSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Scripted InteractiveProcess double: streams a queue of output chunks,
 * optionally running a side effect (e.g. mutating the shared
 * McpLoginSession, simulating another request completing the redirect or
 * cancelling) the moment a given chunk is delivered, and reports itself as
 * no longer running once its input is closed or its chunks run dry (when
 * configured to auto-exit).
 */
final class FakeInteractiveProcess implements InteractiveProcess
{
    private int $callIndex = 0;

    private bool $stopped = false;

    private bool $exited = false;

    /** @var array<int, string> */
    public array $written = [];

    /**
     * @param  array<int, string>  $chunks
     * @param  array<int, Closure>  $sideEffects  keyed by the chunk index they fire after
     */
    public function __construct(
        private array $chunks,
        private readonly array $sideEffects = [],
        private readonly int $exitCode = 0,
        private readonly bool $autoExitWhenChunksExhausted = false,
    ) {}

    /**
     * Side effects are keyed by call index rather than gated behind
     * remaining chunks, so a test can schedule one for a call made after
     * the chunk queue is already exhausted (e.g. to simulate another
     * request mutating the shared session a couple of polls after the job
     * last read it).
     */
    public function incrementalOutput(): string
    {
        $index = $this->callIndex;
        $this->callIndex++;

        $chunk = $this->chunks !== [] ? array_shift($this->chunks) : '';

        if ($chunk !== '' && $this->chunks === [] && $this->autoExitWhenChunksExhausted) {
            $this->exited = true;
        }

        if (isset($this->sideEffects[$index])) {
            ($this->sideEffects[$index])();
        }

        return $chunk;
    }

    public function isRunning(): bool
    {
        return ! $this->stopped && ! $this->exited;
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function closeInput(): void
    {
        $this->exited = true;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function wait(): int
    {
        return $this->exitCode;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}

function runMcpLoginJob(string $server, InteractiveProcess $process): void
{
    app()->instance(InteractiveProcessFactory::class, new class($process) extends InteractiveProcessFactory
    {
        public function __construct(private readonly InteractiveProcess $process) {}

        public function start(string $shellCommand, int $timeout): InteractiveProcess
        {
            return $this->process;
        }
    });

    (new McpLoginJob($server))->handle(app(ClaudeCli::class), app(InteractiveProcessFactory::class));
}

const AUTH_URL_CHUNK = <<<'TXT'
Starting authentication for "linear"…
Visit this URL to authorize:
  https://mcp.linear.app/authorize?response_type=code&client_id=abc&redirect_uri=http%3A%2F%2Flocalhost%3A57772%2Fcallback&state=xyz

Waiting for authorization… (^C to cancel)
Or paste the redirect URL here:
TXT;

beforeEach(function () {
    Sleep::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('captures the authorization URL and moves the session to awaiting_redirect', function () {
    McpLoginSession::start('linear');

    $capturedUrl = null;
    $capturedStatus = null;

    // The session never receives a redirect in this test, so the job would
    // otherwise poll forever; cancel it once the URL has been captured so
    // handle() returns, but only after recording what the session looked
    // like right after the URL was found.
    $process = new FakeInteractiveProcess(
        chunks: [AUTH_URL_CHUNK],
        // Fires on the poll *after* the URL-bearing chunk was delivered,
        // once the job has already saved authorizationUrl/awaiting_redirect.
        sideEffects: [1 => function () use (&$capturedUrl, &$capturedStatus) {
            $session = McpLoginSession::find('linear');
            $capturedUrl = $session->authorizationUrl;
            $capturedStatus = $session->status;

            $session->status = 'cancelled';
            $session->save();
        }],
    );

    runMcpLoginJob('linear', $process);

    expect($capturedStatus)->toBe('awaiting_redirect');
    expect($capturedUrl)->toBe(
        'https://mcp.linear.app/authorize?response_type=code&client_id=abc&redirect_uri=http%3A%2F%2Flocalhost%3A57772%2Fcallback&state=xyz'
    );
    expect(McpLoginSession::find('linear')->status)->toBe('cancelled');
});

it('captures the URL, accepts the redirect, and concludes succeeded', function () {
    McpLoginSession::start('linear');

    $process = new FakeInteractiveProcess(
        chunks: [AUTH_URL_CHUNK],
        sideEffects: [1 => function () {
            $session = McpLoginSession::find('linear');
            expect($session->status)->toBe('awaiting_redirect');
            expect($session->authorizationUrl)->toBe(
                'https://mcp.linear.app/authorize?response_type=code&client_id=abc&redirect_uri=http%3A%2F%2Flocalhost%3A57772%2Fcallback&state=xyz'
            );

            $session->redirectUrl = 'http://localhost:57772/callback?code=abc';
            $session->status = 'finishing';
            $session->save();
        }],
        exitCode: 0,
    );

    runMcpLoginJob('linear', $process);

    $session = McpLoginSession::find('linear');
    expect($session->status)->toBe('succeeded');
    expect($process->written)->toBe(["http://localhost:57772/callback?code=abc\n"]);
});

it('concludes failed when the CLI reports an error and never asks for a redirect', function () {
    McpLoginSession::start('linear');

    $process = new FakeInteractiveProcess(
        chunks: ["Couldn't complete authentication for \"linear\": invalid_grant – the authorization code has expired\n"],
        exitCode: 1,
        autoExitWhenChunksExhausted: true,
    );

    runMcpLoginJob('linear', $process);

    $session = McpLoginSession::find('linear');
    expect($session->status)->toBe('failed');
    expect($session->error)->toContain("Couldn't complete authentication");
});

it('cancels the process and marks the session cancelled', function () {
    McpLoginSession::start('linear');

    $process = new FakeInteractiveProcess(
        chunks: ["some preamble\n"],
        sideEffects: [0 => function () {
            $session = McpLoginSession::find('linear');
            $session->status = 'cancelled';
            $session->save();
        }],
    );

    runMcpLoginJob('linear', $process);

    expect(McpLoginSession::find('linear')->status)->toBe('cancelled');
});

it('expires the session once 10 minutes have passed', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
    McpLoginSession::start('linear');

    Carbon::setTestNow(Carbon::parse('2026-01-01 00:11:00'));

    $process = new FakeInteractiveProcess(chunks: []);

    runMcpLoginJob('linear', $process);

    expect(McpLoginSession::find('linear')->status)->toBe('expired');
});

it('returns immediately when no session exists (e.g. it was removed before the job ran)', function () {
    $process = new FakeInteractiveProcess(chunks: [AUTH_URL_CHUNK]);

    runMcpLoginJob('linear', $process);

    expect(McpLoginSession::find('linear'))->toBeNull();
});
