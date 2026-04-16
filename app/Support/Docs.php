<?php

namespace App\Support;

/**
 * Resolves short doc anchor keys (e.g. `channels.slack`) into absolute
 * URLs on the hosted Yak docs site. Configuration lives in
 * `config/docs.php`. Used by `<x-doc-link>` and any PHP-side callers.
 */
class Docs
{
    /**
     * Resolve a docs anchor key to an absolute URL. Unknown keys fall back
     * to the docs base URL so callers never emit a broken link.
     */
    public static function url(string $anchor = 'home'): string
    {
        $base = (string) config('docs.base_url');

        /** @var array<string, string> $anchors */
        $anchors = (array) config('docs.anchors', []);

        $path = $anchors[$anchor] ?? '';

        return $base . ltrim($path, '/');
    }
}
