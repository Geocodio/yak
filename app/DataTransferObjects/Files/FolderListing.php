<?php

namespace App\DataTransferObjects\Files;

final readonly class FolderListing
{
    /**
     * @param  list<FileEntry>  $entries
     */
    public function __construct(public array $entries, public bool $truncated) {}
}
