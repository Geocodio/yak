<?php

namespace App\Services\Devices;

use App\Models\Repository;

/**
 * Maps a git remote URL on this computer to the Yak repository it points at.
 */
class RepositoryResolver
{
    private const REMOTE_PATTERN = '#^(?:https?://|ssh://git@|git@)github\.com[:/]([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#i';

    public function resolve(string $remote): ?Repository
    {
        if (preg_match(self::REMOTE_PATTERN, trim($remote), $matches) !== 1) {
            return null;
        }

        $fullName = strtolower($matches[1] . '/' . $matches[2]);

        return Repository::query()->whereRaw('LOWER(github_full_name) = ?', [$fullName])->first()
            ?? Repository::query()->whereRaw('LOWER(slug) = ?', [$fullName])->first();
    }
}
