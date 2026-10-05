<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\User;

/**
 * Picks the human who owns a task's outcome: the explicitly named person
 * (a Linear assignee), else whoever started the task, else the
 * repository's default responsible user. Blank names count as missing.
 */
class ResponsiblePersonResolver
{
    public function resolve(?string $explicitName, ?string $authorName, string $repositorySlug): ?string
    {
        foreach ([$explicitName, $authorName] as $name) {
            if ($name !== null && trim($name) !== '') {
                return trim($name);
            }
        }

        $defaultName = Repository::query()
            ->where('slug', $repositorySlug)
            ->with('defaultResponsibleUser')
            ->first()
            ?->defaultResponsibleUser
            ?->name;

        return $defaultName !== null && trim($defaultName) !== '' ? trim($defaultName) : null;
    }

    /**
     * The user counterpart of resolve(): the explicitly named user (a matched
     * Linear assignee), else the starter, else the repository's default
     * responsible user.
     */
    public function resolveUser(?User $explicitUser, ?User $starter, string $repositorySlug): ?User
    {
        return $explicitUser
            ?? $starter
            ?? Repository::query()
                ->where('slug', $repositorySlug)
                ->with('defaultResponsibleUser')
                ->first()
                ?->defaultResponsibleUser;
    }
}
