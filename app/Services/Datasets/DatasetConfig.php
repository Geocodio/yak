<?php

namespace App\Services\Datasets;

use App\Models\Repository;
use App\Services\RepositoryConfig;

/**
 * The validated `data:` section of a repository's `.yak/config.yml`.
 *
 * The parser has already checked every field, so values are safe to place in
 * object keys and mount paths.
 */
final readonly class DatasetConfig
{
    public function __construct(
        public string $name,
        public string $prefix,
        public string $versionKey,
        public string $mountPath,
        public ?int $sizeGb,
    ) {}

    public static function fromRepository(Repository $repository): ?self
    {
        $data = app(RepositoryConfig::class)->snapshot($repository)->data('config.yml');

        if (! is_array($data) || ! is_array($data['data'] ?? null)) {
            return null;
        }

        return self::fromArray($data['data']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            prefix: (string) $data['prefix'],
            versionKey: (string) $data['version_key'],
            mountPath: (string) $data['mount_path'],
            sizeGb: isset($data['size_gb']) ? (int) $data['size_gb'] : null,
        );
    }
}
