<?php

namespace App\Services;

/**
 * Locates the published JSON Schema for each `.yak/` YAML file.
 */
class RepositoryConfigSchemas
{
    private const BASE_URL = 'https://raw.githubusercontent.com/Geocodio/yak/main/schemas/';

    public static function url(string $file): string
    {
        if (! in_array($file, ['config.yml', 'preview.yml', 'risk-profile.yml'], true)) {
            throw new \InvalidArgumentException("No schema for {$file}");
        }

        return self::BASE_URL . basename($file, '.yml') . '.schema.json';
    }
}
