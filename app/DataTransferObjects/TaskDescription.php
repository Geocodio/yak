<?php

namespace App\DataTransferObjects;

readonly class TaskDescription
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $channel,
        public string $externalId,
        public ?string $repository = null,
        public array $metadata = [],
    ) {}
}
