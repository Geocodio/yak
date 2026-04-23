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
        return "yak-tpl-{$this->repoSlug}/ready-v{$this->version}";
    }

    public static function parse(string $raw): ?self
    {
        // Anchor on `/ready-v<digits>` at the end; repo slug is everything between
        // `yak-tpl-` prefix and that suffix. This handles {owner}/{repo} slugs.
        if (preg_match('#^yak-tpl-(?P<slug>.+)/ready-v(?P<v>\d+)$#', $raw, $m)) {
            return new self($m['slug'], (int) $m['v']);
        }

        return null;
    }
}
