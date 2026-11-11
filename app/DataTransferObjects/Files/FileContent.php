<?php

namespace App\DataTransferObjects\Files;

final readonly class FileContent
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $size,
        public bool $isBinary,
        public bool $isTooLarge,
        public ?string $content,
        public ?string $headContent,
        public string $language,
    ) {}

    /**
     * @return array{path: string, sha256: string, size: int, isBinary: bool, isTooLarge: bool, content: ?string, headContent: ?string, language: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'sha256' => $this->sha256,
            'size' => $this->size,
            'isBinary' => $this->isBinary,
            'isTooLarge' => $this->isTooLarge,
            'content' => $this->content,
            'headContent' => $this->headContent,
            'language' => $this->language,
        ];
    }
}
