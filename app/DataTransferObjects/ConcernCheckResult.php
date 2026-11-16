<?php

namespace App\DataTransferObjects;

readonly class ConcernCheckResult
{
    /**
     * @param  list<array{name: string, evidence: string}>  $touchedConcerns
     */
    public function __construct(
        public array $touchedConcerns,
        public ?string $changeKind,
        public bool $isUncertain,
        public ?string $failure = null,
    ) {}

    public static function failed(string $failure): self
    {
        return new self([], null, true, $failure);
    }

    public function isClear(): bool
    {
        return $this->touchedConcerns === []
            && ! $this->isUncertain
            && $this->failure === null
            && $this->changeKind !== null;
    }
}
