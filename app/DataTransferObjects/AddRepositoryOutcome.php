<?php

namespace App\DataTransferObjects;

final readonly class AddRepositoryOutcome
{
    /**
     * @param  'added'|'setup_failed'|'no_access'|'duplicate'|'invalid'  $status
     */
    public function __construct(
        public string $status,
        public ?string $path = null,
        public ?string $message = null,
    ) {}
}
