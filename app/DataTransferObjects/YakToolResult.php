<?php

namespace App\DataTransferObjects;

final readonly class YakToolResult
{
    public function __construct(
        public bool $isError,
        public string $content,
    ) {}
}
