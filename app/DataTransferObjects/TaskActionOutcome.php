<?php

namespace App\DataTransferObjects;

/**
 * What a task action did: the status word the app shows and the sentence the web flashes.
 * An empty sentence means the web has nothing to say.
 */
final readonly class TaskActionOutcome
{
    public function __construct(public string $status, public string $sentence) {}
}
