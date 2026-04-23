<?php

namespace App\DataTransferObjects;

final readonly class TemplateSnapshotRef
{
    public function __construct(
        public string $repoSlug,
        public int $version,
    ) {}

    public function name(): string
    {
        // Sanitize the slug the same way IncusSandboxManager sanitizes
        // the template instance name it creates. Repo slugs like
        // "Geocodio/geocodio" contain characters ("/", uppercase) that
        // Incus reads as snapshot separators or rejects outright, so the
        // concrete Incus object is always `yak-tpl-<lowercased-dashed>`.
        $slug = (string) preg_replace('/[^a-z0-9-]/', '-', strtolower($this->repoSlug));

        return "yak-tpl-{$slug}/ready-v{$this->version}";
    }

    public static function parse(string $raw): ?self
    {
        // Anchor on `/ready-v<digits>` at the end; repo slug is everything between
        // `yak-tpl-` prefix and that suffix. Names that have round-tripped through
        // name() are already sanitized, so the captured slug matches what name()
        // would regenerate.
        if (preg_match('#^yak-tpl-(?P<slug>.+)/ready-v(?P<v>\d+)$#', $raw, $m)) {
            return new self($m['slug'], (int) $m['v']);
        }

        return null;
    }
}
