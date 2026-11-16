<?php

namespace App\DataTransferObjects;

final readonly class PreviewWakeOutcome
{
    /**
     * @param  'ready'|'waking'|'waiting_for_slot'|'destroyed'|'closed'|'local_machine'|'failing'|'no_manifest'  $state
     * @param  list<string>  $steps
     */
    private function __construct(
        public string $state,
        public ?string $host = null,
        public ?int $port = null,
        public array $steps = [],
        public ?string $error = null,
    ) {}

    /** @param list<string> $steps */
    public static function ready(string $host, int $port, array $steps = []): self
    {
        return new self('ready', $host, $port, $steps);
    }

    /** @param list<string> $steps */
    public static function waking(array $steps = []): self
    {
        return new self('waking', steps: $steps);
    }

    /** @param list<string> $steps */
    public static function waitingForSlot(array $steps = []): self
    {
        return new self('waiting_for_slot', steps: $steps);
    }

    public static function destroyed(): self
    {
        return new self('destroyed');
    }

    public static function closed(): self
    {
        return new self('closed');
    }

    public static function localMachine(): self
    {
        return new self('local_machine');
    }

    public static function noManifest(): self
    {
        return new self('no_manifest');
    }

    /** @param list<string> $steps */
    public static function failing(string $error, array $steps = []): self
    {
        return new self('failing', steps: $steps, error: $error);
    }
}
