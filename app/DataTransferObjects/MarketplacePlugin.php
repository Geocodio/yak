<?php

namespace App\DataTransferObjects;

final readonly class MarketplacePlugin
{
    public function __construct(
        public string $name,
        public string $description,
        public string $marketplace,
        public ?string $category,
        public ?string $homepage,
        public ?string $sourceUrl,
        public ?string $author,
    ) {}

    public function key(): string
    {
        return "{$this->name}@{$this->marketplace}";
    }

    public function link(): ?string
    {
        return $this->homepage ?? $this->sourceUrl;
    }
}
