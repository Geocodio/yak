<?php

namespace App\DataTransferObjects;

/**
 * What one `claude -p` stream invocation left behind, before the runner
 * decides whether that amounts to a result, a resume, or a failure.
 */
final readonly class StreamOutcome
{
    /**
     * @param  array<string, mixed>|null  $resultEvent
     */
    public function __construct(
        public ?array $resultEvent,
        public string $stderr,
        public int $exitCode,
        public int $lineCount,
        public ?string $forcedTermination,
    ) {}

    /**
     * The CLI exited on its own, cleanly, and never emitted a `result`
     * event. Seen on task 5585: Claude committed and wrote its summary,
     * then the process ended one second later with exit 0 and no stderr.
     */
    public function endedCleanlyWithoutResult(): bool
    {
        return $this->resultEvent === null
            && $this->exitCode === 0
            && $this->stderr === ''
            && $this->forcedTermination === null;
    }
}
