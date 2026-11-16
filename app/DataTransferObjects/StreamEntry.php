<?php

namespace App\DataTransferObjects;

final readonly class StreamEntry
{
    /**
     * @param  array<string, mixed>  $line
     * @param  bool  $fromBroker  true only when the broker wrote the entry, as marked by a stream field the runner cannot set
     */
    public function __construct(
        public string $channel,
        public string $id,
        public int $generation,
        public int $sequence,
        public array $line,
        public bool $fromBroker = false,
    ) {}
}
