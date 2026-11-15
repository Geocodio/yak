<?php

namespace App\DataTransferObjects\Files;

/** Why a sandbox cannot serve files right now. */
final readonly class SandboxUnavailable
{
    public const WAKING = 'waking';

    public const ASLEEP = 'asleep';

    public const DESTROYED = 'destroyed';

    public const CLOSED_DESTROYED = 'closed_destroyed';

    public const ON_DEVICE = 'on_device';

    public function __construct(
        public string $state,
        public bool $canRestore = false,
        public bool $waitingForSlot = false,
        public bool $restoring = false,
    ) {}

    public function message(): string
    {
        return match (true) {
            $this->state === self::ASLEEP => 'The sandbox is asleep. It wakes when someone who can drive this thread opens it.',
            $this->state === self::DESTROYED => 'The sandbox was removed.',
            $this->state === self::CLOSED_DESTROYED => 'This thread is closed, so its sandbox is gone.',
            $this->state === self::ON_DEVICE => 'This thread runs on a computer, so its files are there.',
            $this->waitingForSlot => 'The sandbox is waiting for a free slot.',
            $this->restoring => 'The sandbox is being restored.',
            default => 'The sandbox is waking up.',
        };
    }

    /**
     * @return array{state: string, canRestore: bool, waitingForSlot: bool, restoring: bool}
     */
    public function details(): array
    {
        return [
            'state' => $this->state,
            'canRestore' => $this->canRestore,
            'waitingForSlot' => $this->waitingForSlot,
            'restoring' => $this->restoring,
        ];
    }

    /**
     * The body the web answers with: a sleeping sandbox reads as waking there.
     *
     * @return array<string, mixed>
     */
    public function webBody(): array
    {
        return match ($this->state) {
            self::ASLEEP => ['state' => self::WAKING, 'waitingForSlot' => false, 'restoring' => false],
            self::WAKING => ['state' => self::WAKING, 'waitingForSlot' => $this->waitingForSlot, 'restoring' => $this->restoring],
            self::DESTROYED => ['state' => self::DESTROYED, 'canRestore' => $this->canRestore],
            default => ['state' => $this->state],
        };
    }
}
