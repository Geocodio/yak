<?php

namespace App\Http\Resources;

/**
 * Value mappers the app's thread answers share, so the show and the Review list never disagree.
 */
final class AppPullRequestData
{
    /**
     * @return 'passing'|'failing'|'pending'|null
     */
    public static function checks(?string $ciStatus): ?string
    {
        return match ($ciStatus) {
            'passing' => 'passing',
            'failing' => 'failing',
            'running' => 'pending',
            default => null,
        };
    }

    /**
     * Only a dirty merge state is a conflict; no stored state is unknown.
     *
     * @return 'conflicts'|'clean'|'unknown'
     */
    public static function mergeability(?string $mergeableState): string
    {
        return match ($mergeableState) {
            'dirty' => 'conflicts',
            null, 'unknown' => 'unknown',
            default => 'clean',
        };
    }
}
