<?php

namespace App\Services\Threads;

use App\DataTransferObjects\RefreshResult;
use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Models\Thread;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Serves the `request_refresh` tool: runs the refresh commands the changed
 * files call for, with build credentials in the exec environment only.
 */
class RefreshToolRouter implements YakToolRouter
{
    private const MAX_BYTES = 20000;

    public function __construct(
        private readonly YakToolRouter $inner,
        private readonly RefreshTriggers $refresh,
    ) {}

    public function handle(Thread $thread, YakToolCall $call): YakToolResult
    {
        return match ($call->tool) {
            'request_refresh' => $this->refresh($thread),
            default => $this->inner->handle($thread, $call),
        };
    }

    private function refresh(Thread $thread): YakToolResult
    {
        try {
            $results = $this->refresh->run($thread);

            if ($results === []) {
                return new YakToolResult(false, 'Nothing to refresh.');
            }

            $text = implode("\n\n", array_map(
                fn (RefreshResult $result): string => "$ {$result->command}\nexit {$result->exitCode}\n{$result->output}",
                $results,
            ));

            $secrets = array_filter($this->refresh->buildEnvironment($thread), fn (string $value): bool => $value !== '');
            $text = str_replace($secrets, '[redacted]', $text);
            $text = (string) preg_replace('#(://)[^/@\s]+@#', '$1[redacted]@', $text);

            return new YakToolResult(! end($results)->succeeded(), mb_strcut($text, 0, self::MAX_BYTES));
        } catch (Throwable $exception) {
            Log::warning('request_refresh failed', ['thread' => $thread->id, 'error' => $exception::class]);

            return new YakToolResult(true, 'The refresh could not run.');
        }
    }
}
