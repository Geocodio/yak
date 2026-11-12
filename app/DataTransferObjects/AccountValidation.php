<?php

namespace App\DataTransferObjects;

final readonly class AccountValidation
{
    public function __construct(
        public bool $valid,
        public ?string $error = null,
    ) {}
}
