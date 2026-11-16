<?php

namespace App\Support;

/**
 * The repository-agnostic hard floor: files a diff may never touch without a
 * human approving it.
 */
class ApprovalFloor
{
    /**
     * @param  array<int, array{filename?: string, previous_filename?: string}>  $files
     * @return list<string>
     */
    public static function heldPaths(array $files): array
    {
        $patterns = array_merge(
            (array) config('yak.pr_review.approval_veto_paths', []),
            (array) config('yak.pr_review.approval_blocked_paths', []),
            (array) config('yak.pr_review.config_floor_paths', []),
        );
        $held = [];

        foreach ($files as $file) {
            foreach ([$file['filename'] ?? null, $file['previous_filename'] ?? null] as $path) {
                if (is_string($path) && $path !== '' && PathMatcher::matches($path, $patterns)) {
                    $held[] = $path;
                }
            }
        }

        return array_values(array_unique($held));
    }
}
