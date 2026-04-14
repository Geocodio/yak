<?php

namespace App\DataTransferObjects;

final readonly class BundledSkill
{
    public function __construct(
        public string $name,
        public string $description,
        public string $path,
    ) {}
}
