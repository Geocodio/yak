<?php

namespace App\DataTransferObjects;

/**
 * Outcome of `claude plugin update <plugin>`. The CLI exits 0 both when it
 * installed a newer version and when the plugin was already current, so the
 * caller needs to know which happened to describe it honestly.
 */
final readonly class PluginUpdateResult
{
    public function __construct(
        public bool $updated,
        public ?string $version,
    ) {}

    public static function fromCliOutput(string $output): self
    {
        $version = preg_match('/\(([^()]+)\)\s*\.?\s*$/m', trim($output), $matches) === 1
            ? trim($matches[1])
            : null;

        if (preg_match('/already (?:at|on) the latest version/i', $output) === 1) {
            return new self(updated: false, version: $version);
        }

        return new self(updated: true, version: $version);
    }
}
