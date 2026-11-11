<?php

namespace App\DataTransferObjects;

final readonly class ThreadPreviewManifestResult
{
    private function __construct(
        public string $status,
        public ?PreviewManifest $manifest,
        public ?string $error,
    ) {}

    public static function found(PreviewManifest $manifest): self
    {
        return new self('found', $manifest, null);
    }

    public static function missing(): self
    {
        return new self('missing', null, null);
    }

    public static function invalid(string $error): self
    {
        return new self('invalid', null, $error);
    }
}
