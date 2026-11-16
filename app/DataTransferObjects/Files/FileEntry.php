<?php

namespace App\DataTransferObjects\Files;

final readonly class FileEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $isDirectory,
        public bool $isWritable,
        public ?int $size,
    ) {}

    /**
     * @return array{name: string, path: string, isDirectory: bool, isWritable: bool, size: ?int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'isDirectory' => $this->isDirectory,
            'isWritable' => $this->isWritable,
            'size' => $this->size,
        ];
    }
}
