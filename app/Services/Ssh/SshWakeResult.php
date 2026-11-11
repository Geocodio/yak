<?php

namespace App\Services\Ssh;

/**
 * What the wake daemon is told about a sandbox: ready, waiting, destroyed, closed or no_sandbox.
 */
final readonly class SshWakeResult
{
    public function __construct(
        public string $state,
        public ?string $container = null,
        public ?string $message = null,
    ) {}

    /**
     * @return array{state: string, container: string|null, message: string|null}
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'container' => $this->container, 'message' => $this->message];
    }
}
