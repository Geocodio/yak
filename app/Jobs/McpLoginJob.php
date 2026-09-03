<?php

namespace App\Jobs;

use App\ClaudeCli;
use App\Services\InteractiveProcess;
use App\Services\InteractiveProcessFactory;
use App\Support\McpLoginSession;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Drives `claude mcp login <name> --no-browser` under a PTY (via `script`,
 * since the CLI refuses to print its authorization URL without one) and
 * keeps the shared McpLoginSession record in the cache updated so the
 * Settings > MCP servers page can poll it across requests.
 *
 * The CLI's own auth flow spawns a local callback server and waits for a
 * browser redirect that can never reach it from inside the container, so
 * this job intercepts the printed authorization URL, waits for the user to
 * paste back the (unreachable) localhost redirect URL via
 * McpLoginController@redirect, then feeds that URL to the CLI's stdin as
 * if it had been the browser.
 */
class McpLoginJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 660;

    public int $tries = 1;

    /** Overall session lifetime — mirrors McpLoginSession::start()'s expiresAt. */
    private const SESSION_MINUTES = 10;

    /** How long to wait for the CLI to exit once the redirect URL is sent. */
    private const EXIT_GRACE_SECONDS = 60;

    public function __construct(public readonly string $server)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->server;
    }

    public function uniqueFor(): int
    {
        return 660;
    }

    public function handle(ClaudeCli $cli, InteractiveProcessFactory $factory): void
    {
        $session = McpLoginSession::find($this->server);

        if ($session === null || $session->status === 'cancelled') {
            return;
        }

        $args = 'mcp login ' . escapeshellarg($this->server) . ' --no-browser';
        $command = 'script -qec ' . escapeshellarg($cli->interactiveCommand($args)) . ' /dev/null';

        Log::info('McpLoginJob: starting login', ['server' => $this->server]);

        $process = $factory->start($command, $this->timeout);

        $this->run($process, $session);
    }

    private function run(InteractiveProcess $process, McpLoginSession $session): void
    {
        $deadline = $session->startedAt->clone()->addMinutes(self::SESSION_MINUTES);

        $buffer = '';
        $foundUrl = false;
        $redirectSent = false;
        $redirectSentAt = null;

        while (true) {
            $session = McpLoginSession::find($this->server);

            if ($session === null || $session->status === 'cancelled') {
                $process->stop();
                $this->conclude('cancelled');

                return;
            }

            if (now()->greaterThan($deadline)) {
                $process->stop();
                $this->conclude('expired');

                return;
            }

            $buffer .= $process->incrementalOutput();
            $stripped = $this->stripAnsi($buffer);

            if (! $foundUrl) {
                $url = $this->extractAuthorizationUrl($stripped);

                if ($url !== null) {
                    $foundUrl = true;
                    $session->authorizationUrl = $url;
                    $session->status = 'awaiting_redirect';
                    $session->save();
                    Log::info('McpLoginJob: awaiting redirect', ['server' => $this->server]);
                }
            }

            if ($foundUrl && ! $redirectSent && $session->status === 'finishing' && $session->redirectUrl !== null) {
                $process->write($session->redirectUrl . "\n");
                $process->closeInput();
                $redirectSent = true;
                $redirectSentAt = now();
                Log::info('McpLoginJob: sent redirect url', ['server' => $this->server]);
            }

            if (! $process->isRunning()) {
                break;
            }

            if ($redirectSent && $redirectSentAt !== null && $redirectSentAt->diffInSeconds(now()) > self::EXIT_GRACE_SECONDS) {
                $process->stop();
                break;
            }

            Sleep::for($foundUrl ? 500 : 200)->milliseconds();
        }

        $buffer .= $process->incrementalOutput();
        $exitCode = $process->wait();
        $stripped = $this->stripAnsi($buffer);

        $failed = $exitCode !== 0 || preg_match("/(couldn't complete|failed)/i", $stripped) === 1;

        $failed
            ? $this->conclude('failed', $this->lastNonEmptyLine($stripped))
            : $this->conclude('succeeded');
    }

    private function conclude(string $status, ?string $error = null): void
    {
        $session = McpLoginSession::find($this->server) ?? McpLoginSession::start($this->server);
        $session->status = $status;
        $session->error = $error;
        $session->save();

        Log::info('McpLoginJob: finished', ['server' => $this->server, 'status' => $status]);
    }

    private function extractAuthorizationUrl(string $strippedText): ?string
    {
        if (preg_match('/Visit this URL to authorize.*?(https?:\/\/\S+)/is', $strippedText, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * Strips ANSI CSI sequences and OSC 8 hyperlink wrappers from CLI
     * output run under a PTY. OSC 8 sequences are stripped whole
     * (including their URI parameter), not just their prefix — the
     * visible link text usually repeats the URL, so leaving the URI
     * parameter in place would duplicate it.
     */
    private function stripAnsi(string $text): string
    {
        $text = preg_replace('/\x1b\]8;;[^\x07\x1b]*(?:\x1b\\\\|\x07)/', '', $text) ?? $text;
        $text = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text) ?? $text;

        return str_replace(["\x1b\\", "\x07"], '', $text);
    }

    private function lastNonEmptyLine(string $text): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn (string $line) => $line !== '',
        ));

        return $lines === [] ? 'claude mcp login failed' : (string) end($lines);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('yak')->error('McpLoginJob: failed after retries', [
            'server' => $this->server,
            'error' => $e->getMessage(),
        ]);

        $session = McpLoginSession::find($this->server);

        if ($session !== null && ! $session->isTerminal()) {
            $session->status = 'failed';
            $session->error = $e->getMessage();
            $session->save();
        }
    }
}
