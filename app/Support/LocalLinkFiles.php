<?php

namespace App\Support;

/**
 * Detects dependency manifests that link a sibling checkout by sandbox path.
 * Such a link only resolves inside the sandbox, so it never belongs in a commit.
 */
final class LocalLinkFiles
{
    private const MANIFESTS = ['composer.json', 'composer.lock', 'package.json'];

    public static function isManifest(string $path): bool
    {
        return in_array(basename($path), self::MANIFESTS, true);
    }

    /** True when an added line of the diff points a path repository or file:/link: dependency into the sandbox. */
    public static function carriesLocalLink(string $diff): bool
    {
        return preg_match('#^\+(?!\+\+).*(?:"|file:|link:)/(?:extras/|workspace)#m', $diff) === 1;
    }
}
