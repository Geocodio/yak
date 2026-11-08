<?php

namespace App\DataTransferObjects;

final readonly class StreamEntry
{
    /**
     * @param  array<string, mixed>  $line
     */
    public function __construct(
        public string $channel,
        public string $id,
        public int $generation,
        public int $sequence,
        public array $line,
    ) {}
}
