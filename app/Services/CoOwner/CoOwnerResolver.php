<?php

namespace App\Services\CoOwner;

use App\Channels\GitHub\AppService;
use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;

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
        /** @var list<User> $users */
        $users = [];
        $attempts = [];

        $skipIds = function () use (&$users, $requester): array {
            $ids = [];
            foreach ($users as $user) {
                $ids[] = $user->id;
            }
            if ($requester !== null) {
                $ids[] = $requester->id;
            }

            return $ids;
        };

        $record = function (string $entry, string $kind, array $resolved) use (&$users, &$attempts): void {
            if ($resolved['outcome'] !== 'asked') {
                $attempts[] = ['entry' => $entry, 'kind' => $kind, 'outcome' => $resolved['outcome']];

                return;
            }

            foreach ($resolved['users'] as $user) {
                $users[] = $user;
                $attempts[] = ['entry' => $entry, 'kind' => $kind, 'outcome' => 'asked'];
            }
        };

        $changesRequester = $this->changesRequester($task);
        if ($changesRequester !== null) {
            $record($changesRequester->name, 'changes_requester', $this->classify($repository, 'changes_requester', [$changesRequester], $skipIds(), self::MAX_ASKED - count($users)));
        }

        foreach ($codeOwnerEntries as $entry) {
            if (count($users) >= self::MAX_ASKED) {
                break;
            }
            $resolved = $this->resolveEntry($repository, $entry, self::MAX_ASKED - count($users), $skipIds());
            $record($entry, $resolved['kind'], $resolved);
        }

        $fallback = $repository->defaultResponsibleUser;
        if ($fallback !== null && count($users) < self::MAX_ASKED) {
            $record($fallback->name, 'fallback', $this->classify($repository, 'fallback', [$fallback], $skipIds(), self::MAX_ASKED - count($users)));
        }

        return ['users' => $users, 'attempts' => $attempts];
    }

    /**
     * One CODEOWNERS entry: `@login`, `@org/team` or an email. With a limit,
     * access checks stop once that many users are found; users in $skipUserIds
     * are never returned or checked. When every candidate is skipped the
     * outcome is `asked` with no users, which callers do not record.
     *
     * @param  list<int>  $skipUserIds
     * @return array{users: list<User>, kind: string, outcome: string}
     */
    public function resolveEntry(Repository $repository, string $entry, ?int $limit = null, array $skipUserIds = []): array
    {
        if (preg_match('/^@([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)$/', $entry, $matches) === 1) {
            return $this->resolveTeam($repository, $matches[1], $matches[2], $limit, $skipUserIds);
        }

        if (preg_match('/^@[A-Za-z0-9_.-]+$/', $entry) === 1) {
            $candidates = array_values(User::query()
                ->whereNotNull('github_id')
                ->whereRaw('lower(github_login) = ?', [mb_strtolower(substr($entry, 1))])
                ->get()
                ->all());

            return $this->classify($repository, 'login', $candidates, $skipUserIds, $limit);
        }

        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $entry) === 1) {
            $user = User::findByEmail($entry);

            return $this->classify($repository, 'email', $user === null ? [] : [$user], $skipUserIds, $limit);
        }

        return ['users' => [], 'kind' => 'login', 'outcome' => 'unknown'];
    }

    /**
     * @param  list<int>  $skipUserIds
     * @return array{users: list<User>, kind: string, outcome: string}
     */
    private function resolveTeam(Repository $repository, string $organization, string $team, ?int $limit, array $skipUserIds): array
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

        $members = array_map('mb_strtolower', array_slice($logins, 0, self::MAX_TEAM_MEMBERS_CHECKED));
        $linked = User::query()
            ->whereNotNull('github_id')
            ->whereIn(DB::raw('lower(github_login)'), $members)
            ->get()
            ->keyBy(fn (User $user): string => mb_strtolower((string) $user->github_login));

        $candidates = [];
        foreach ($members as $login) {
            $member = $linked->get($login);
            if ($member instanceof User) {
                $candidates[] = $member;
            }
        }

        return $this->classify($repository, 'team', $candidates, $skipUserIds, $limit);
    }

    /**
     * @param  list<User>  $candidates
     * @param  list<int>  $skipUserIds
     * @return array{users: list<User>, kind: string, outcome: string}
     */
    private function classify(Repository $repository, string $kind, array $candidates, array $skipUserIds, ?int $limit): array
    {
        if ($candidates === []) {
            return ['users' => [], 'kind' => $kind, 'outcome' => 'not_linked'];
        }

        $candidates = array_values(array_filter($candidates, fn (User $user): bool => ! in_array($user->id, $skipUserIds, true)));
        if ($candidates === [] || ($limit !== null && $limit <= 0)) {
            return ['users' => [], 'kind' => $kind, 'outcome' => 'asked'];
        }

        $users = [];
        $outcomes = [];
        foreach ($candidates as $candidate) {
            if ($limit !== null && count($users) >= $limit) {
                break;
            }
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
