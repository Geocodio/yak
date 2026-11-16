<?php

namespace App\DataTransferObjects\Files;

final readonly class SaveOutcome
{
    public function __construct(
        public bool $saved,
        public ?FileContent $current,
        public ?FileContent $written,
        public bool $unchanged = false,
    ) {}

    /**
     * @return array{state: string, file: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->saved ? 'saved' : 'conflict',
            'file' => ($this->saved ? $this->written : $this->current)?->toArray(),
        ];
    }
}
