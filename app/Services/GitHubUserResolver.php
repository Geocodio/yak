<?php

namespace App\Services;

use App\Models\User;

/**
 * Finds the Yak user behind a GitHub login. Only users who linked their
 * GitHub account match, and the login is compared case-insensitively.
 */
class GitHubUserResolver
{
    public function findByLogin(string $login): ?User
    {
        return $this->allByLogin($login)[0] ?? null;
    }

    /**
     * @return list<User>
     */
    public function allByLogin(string $login): array
    {
        return array_values(User::query()
            ->whereNotNull('github_id')
            ->whereRaw('lower(github_login) = ?', [mb_strtolower(ltrim($login, '@'))])
            ->orderBy('id')
            ->get()
            ->all());
    }
}
