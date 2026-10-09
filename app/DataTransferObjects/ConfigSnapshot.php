<?php

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * Every `.yak/` file Yak holds for one repository, plus how the last read went.
 *
 * `read` means the files match the default branch head Yak last saw,
 * `unreachable` means GitHub could not be read and stored versions are in
 * use, and `unavailable` means this installation does not read `.yak/`.
 */
final readonly class ConfigSnapshot
{
    /** @param  array<string, ConfigFile>  $files  keyed by file name; a missing key means the file does not exist */
    public function __construct(
        public string $state,
        public ?string $commitSha,
        public ?CarbonImmutable $readAt,
        public ?string $readError,
        public array $files,
    ) {}

    public static function unavailable(): self
    {
        return new self('unavailable', null, null, null, []);
    }

    public function file(string $name): ?ConfigFile
    {
        return $this->files[$name] ?? null;
    }

    public function data(string $name): mixed
    {
        return $this->file($name)?->data;
    }
}
