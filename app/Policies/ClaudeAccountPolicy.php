<?php

namespace App\Policies;

use App\Models\ClaudeAccount;
use App\Models\User;

class ClaudeAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user, bool $system = false): bool
    {
        return ! $system || $this->isAdmin($user);
    }

    /** A person manages their own accounts; only an admin manages the Yak-owned ones. */
    public function manage(User $user, ClaudeAccount $account): bool
    {
        return $account->isSystem() ? $this->isAdmin($user) : $account->owner_user_id === $user->id;
    }

    public function useAsUnattended(User $user): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->id, config('claude-accounts.admin_user_ids'), true);
    }
}
