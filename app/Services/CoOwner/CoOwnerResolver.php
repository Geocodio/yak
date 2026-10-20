<?php

namespace App\Services\CoOwner;

use App\Channels\GitHub\AppService;
use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;

/**
 * Chooses the people Yak asks to co-own a held change: whoever requested
 * changes last, then the CODEOWNERS owners in the order given, then the
 * repository's fallback owner. Only linked users with write access count, the
 * requester is never asked, and at most three people are.
 *
 * @phpstan-type Outcome 'asked'|'not_linked'|'no_write_access'|'team_skipped'|'unknown'|'error'
 * @phpstan-type Kind 'changes_requester'|'login'|'team'|'email'|'fallback'
 */
class CoOwnerResolver
{
    public const MAX_ASKED = 3;

    public const MAX_TEAM_MEMBERS_CHECKED = 50;

    public function __construct(
        private GitHubWriteAccess $writeAccess,
        private AppService $github,
        private TaskRequester $requesters,
    ) {}

    /**
     * @param  list<string>  $codeOwnerEntries  ranked, most held files first
     * @return array{users: list<User>, attempts: list<array{entry: string, kind: string, outcome: string}>}
     */
    public function resolve(Repository $repository, YakTask $task, array $codeOwnerEntries): array
    {
        $requester = $this->requesters->for($task);
        $users = [];
        $attempts = [];

        $add = function (array $candidates) use (&$users, $requester): bool {
            foreach ($candidates as $candidate) {
                $isRequester = $requester !== null && $candidate->is($requester);
                $isDuplicate = collect($users)->contains(fn (User $user): bool => $user->is($candidate));
                if (! $isRequester && ! $isDuplicate && count($users) < self::MAX_ASKED) {
                    $users[] = $candidate;
                }
            }

            return count($users) >= self::MAX_ASKED;
        };

        $changesRequester = $this->changesRequester($task);
        if ($changesRequester !== null) {
            $outcome = $this->outcomeFor($repository, $changesRequester);
            $attempts[] = ['entry' => $changesRequester->name, 'kind' => 'changes_requester', 'outcome' => $outcome];
            if ($outcome === 'asked') {
                $add([$changesRequester]);
            }
        }

        foreach ($codeOwnerEntries as $entry) {
            if (count($users) >= self::MAX_ASKED) {
                break;
            }
            $resolved = $this->resolveEntry($repository, $entry);
            $attempts[] = ['entry' => $entry, 'kind' => $resolved['kind'], 'outcome' => $resolved['outcome']];
            $add($resolved['users']);
        }

        $fallback = $repository->defaultResponsibleUser;
        if ($fallback !== null && count($users) < self::MAX_ASKED) {
            $outcome = $this->outcomeFor($repository, $fallback);
            $attempts[] = ['entry' => $fallback->name, 'kind' => 'fallback', 'outcome' => $outcome];
            if ($outcome === 'asked') {
                $add([$fallback]);
            }
        }

        return ['users' => $users, 'attempts' => $attempts];
    }

    /**
     * One CODEOWNERS entry: `@login`, `@org/team` or an email.
     *
     * @return array{users: list<User>, kind: string, outcome: string}
     */
    public function resolveEntry(Repository $repository, string $entry): array
    {
        if (preg_match('/^@([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)$/', $entry, $matches) === 1) {
            return $this->resolveTeam($repository, $entry, $matches[1], $matches[2]);
        }

        if (preg_match('/^@[A-Za-z0-9_.-]+$/', $entry) === 1) {
            $candidates = array_values(User::query()->whereNotNull('github_id')->where('github_login', substr($entry, 1))->get()->all());

            return $this->classify($repository, 'login', $candidates);
        }

        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $entry) === 1) {
            $user = User::findByEmail($entry);

            return $this->classify($repository, 'email', $user === null ? [] : [$user]);
        }

        return ['users' => [], 'kind' => 'login', 'outcome' => 'unknown'];
    }

    /** @return array{users: list<User>, kind: string, outcome: string} */
    private function resolveTeam(Repository $repository, string $entry, string $organization, string $team): array
    {
        try {
            $logins = $this->github->listTeamMemberLogins((int) config('yak.channels.github.installation_id'), $organization, $team);
        } catch (\Throwable $exception) {
            report($exception);

            return ['users' => [], 'kind' => 'team', 'outcome' => 'error'];
        }

        if ($logins === null) {
            return ['users' => [], 'kind' => 'team', 'outcome' => 'team_skipped'];
        }

        $candidates = array_values(User::query()
            ->whereNotNull('github_id')
            ->whereIn('github_login', array_slice($logins, 0, self::MAX_TEAM_MEMBERS_CHECKED))
            ->get()->all());

        return $this->classify($repository, 'team', $candidates);
    }

    /**
     * @param  list<User>  $candidates
     * @return array{users: list<User>, kind: string, outcome: string}
     */
    private function classify(Repository $repository, string $kind, array $candidates): array
    {
        if ($candidates === []) {
            return ['users' => [], 'kind' => $kind, 'outcome' => 'not_linked'];
        }

        $users = [];
        $outcomes = [];
        foreach ($candidates as $candidate) {
            $outcome = $this->outcomeFor($repository, $candidate);
            $outcomes[] = $outcome;
            if ($outcome === 'asked') {
                $users[] = $candidate;
            }
        }

        $outcome = match (true) {
            $users !== [] => 'asked',
            in_array('error', $outcomes, true) => 'error',
            in_array('no_write_access', $outcomes, true) => 'no_write_access',
            default => 'not_linked',
        };

        return ['users' => $users, 'kind' => $kind, 'outcome' => $outcome];
    }

    private function outcomeFor(Repository $repository, User $user): string
    {
        return match ($this->writeAccess->check($repository, $user)) {
            'write' => 'asked',
            'read' => 'no_write_access',
            'unlinked' => 'not_linked',
            default => 'error',
        };
    }

    /** The user who most recently requested changes on any task of the conversation. */
    private function changesRequester(YakTask $task): ?User
    {
        $request = CoOwnerRequest::query()
            ->whereIn('task_id', $task->conversation()->pluck('id'))
            ->where('status', CoOwnerRequestStatus::ChangesRequested)
            ->whereNotNull('decided_by_user_id')
            ->latest('decided_at')
            ->latest('id')
            ->first();

        return $request?->decidedBy;
    }
}
