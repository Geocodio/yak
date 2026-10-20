<?php

namespace App\Services\CoOwner;

use App\Channels\GitHub\AppService;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Resources\RiskProfilePageData;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RiskProfileVerdict;
use App\Services\TaskLogger;
use App\Support\CodeOwners;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Holds a risky change on an enforced repository before its pull request
 * opens. Every failure holds: the gate never opens a PR it could not judge.
 */
class CoOwnerGate
{
    private const LISTING_CAP = 300;

    private const LISTING_MESSAGE = 'GitHub compares at most 300 files, so Yak cannot see every file and holds this change.';

    private const EXPIRY_DAYS = 7;

    private const EXEMPT_SOURCES = ['sentry', 'flaky-test'];

    public function __construct(
        private AppService $github,
        private RiskProfileVerdict $verdict,
        private TaskRequester $requesters,
        private GitHubWriteAccess $writeAccess,
        private CoOwnerResolver $resolver,
    ) {}

    /**
     * Returns null when the change may open its PR, or the request that now
     * holds the task. A task that is already held gets its existing request.
     */
    public function hold(YakTask $task, Repository $repository): ?CoOwnerRequest
    {
        if ($this->isExempt($task)) {
            return null;
        }

        try {
            if ($repository->settings()->coOwnerGateMode() !== 'enforce') {
                return null;
            }

            $existing = $this->existingRequest($task);
            if ($existing !== null) {
                return $existing;
            }

            $requester = $this->requesters->for($task);
            if ($requester !== null && $this->writeAccess->hasWriteAccess($repository, $requester)) {
                return null;
            }

            $outcome = $this->judge($task, $repository);
            if ($outcome === null) {
                return null;
            }
        } catch (Throwable $exception) {
            report($exception);
            $outcome = $this->outcome(['gate_error'], false);
        }

        return $this->createRequest($task, $repository, $outcome);
    }

    private function isExempt(YakTask $task): bool
    {
        return in_array($task->mode, [TaskMode::Setup, TaskMode::Review, TaskMode::Research], true)
            || in_array($task->source, self::EXEMPT_SOURCES, true)
            || str_starts_with((string) $task->external_id, 'risk-profile-');
    }

    private function existingRequest(YakTask $task): ?CoOwnerRequest
    {
        $request = CoOwnerRequest::where('task_id', $task->id)->where('status', CoOwnerRequestStatus::Pending)->latest('id')->first();

        if ($request === null && $task->status === TaskStatus::AwaitingCoOwner) {
            $request = CoOwnerRequest::where('task_id', $task->id)->latest('id')->first();
        }

        return $request;
    }

    /**
     * @return array<string, mixed>|null null when the change passes
     */
    private function judge(YakTask $task, Repository $repository): ?array
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        try {
            $changed = $this->github->compareFilesOrFail($installationId, (string) $repository->github_full_name, (string) $repository->default_branch, (string) $task->branch_name);
        } catch (Throwable $exception) {
            report($exception);
            $changed = null;
        }

        if ($changed === null) {
            return $this->outcome(['compare_failed'], true);
        }

        $settings = $repository->settings();
        $profile = RiskProfilePageData::effectiveProfile($repository, $settings);

        if ($profile === null) {
            return $this->outcome(['no_profile'], true, changed: $changed, isEverythingHeld: true);
        }

        $attempts = [];
        $sha = $settings->snapshot->commitSha;
        $codeOwners = CodeOwners::empty();
        if ($sha !== null) {
            try {
                $codeOwners = RiskProfilePageData::codeOwners($repository, $sha);
            } catch (Throwable $exception) {
                report($exception);
                $attempts[] = ['entry' => 'CODEOWNERS', 'kind' => 'codeowners', 'outcome' => 'error'];
            }
        }

        $areas = array_values((array) ($profile['areas'] ?? []));
        $verdict = $this->verdict->evaluate($changed, $areas, $codeOwners, self::LISTING_CAP, self::LISTING_MESSAGE);

        if (! $verdict['isHeld']) {
            return null;
        }

        $reasons = array_values(array_filter([
            $verdict['isFloorHeld'] ? 'config_floor' : null,
            $verdict['isUncheckedHeld'] ? 'unchecked_files' : null,
            $verdict['capReason'] === null ? null : 'capped_' . $verdict['capReason'],
        ]));

        $matchedAreas = [];
        foreach ($verdict['heldAreas'] as $area) {
            $matchedAreas[] = [
                'name' => $area['name'],
                'risk' => $area['risk'],
                'rationale' => (string) ($areas[$area['index']]['rationale'] ?? ''),
                'paths' => array_values(array_map(
                    fn (array $file): string => $file['path'],
                    array_filter($verdict['files'], fn (array $file): bool => $file['isHeld'] && ($file['area']['index'] ?? null) === $area['index']),
                )),
            ];
        }

        $isHeldByPath = [];
        foreach ($verdict['files'] as $file) {
            $isHeldByPath[$file['path']] = $file['isHeld'];
        }

        return $this->outcome(
            $reasons,
            true,
            changed: $changed,
            isHeldByPath: $isHeldByPath,
            matchedAreas: $matchedAreas,
            profile: $profile,
            askEntries: $verdict['asks'],
            attempts: $attempts,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<array<string, mixed>>  $changed
     * @param  array<string, bool>  $isHeldByPath
     * @param  list<array<string, mixed>>  $matchedAreas
     * @param  array<string, mixed>|null  $profile
     * @param  list<string>  $askEntries
     * @param  list<array{entry: string, kind: string, outcome: string}>  $attempts
     * @return array<string, mixed>
     */
    private function outcome(array $reasons, bool $isAsking, array $changed = [], array $isHeldByPath = [], bool $isEverythingHeld = false, array $matchedAreas = [], ?array $profile = null, array $askEntries = [], array $attempts = []): array
    {
        $files = [];
        foreach ($changed as $file) {
            $path = (string) ($file['filename'] ?? '');
            $files[] = [
                'path' => $path,
                'previousPath' => isset($file['previous_filename']) ? (string) $file['previous_filename'] : null,
                'status' => match ((string) ($file['status'] ?? '')) {
                    'added' => 'new',
                    'removed' => 'removed',
                    'renamed' => 'renamed',
                    default => 'modified',
                },
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
                'isHeld' => $isEverythingHeld || ($isHeldByPath[$path] ?? false),
            ];
        }

        return [
            'reasons' => $reasons,
            'isAsking' => $isAsking,
            'files' => $files,
            'matchedAreas' => $matchedAreas,
            'source' => $profile === null ? 'none' : (($profile['source'] ?? null) === 'file' ? 'file' : 'database'),
            'sourceSha' => $profile !== null && ($profile['source'] ?? null) === 'file' ? ($profile['source_sha'] ?? null) : null,
            'askEntries' => $askEntries,
            'attempts' => $attempts,
        ];
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function createRequest(YakTask $task, Repository $repository, array $outcome): CoOwnerRequest
    {
        $users = [];
        $attempts = $outcome['attempts'];

        if ($outcome['isAsking']) {
            try {
                $resolved = $this->resolver->resolve($repository, $task, $outcome['askEntries']);
                $users = $resolved['users'];
                $attempts = [...$attempts, ...$resolved['attempts']];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $request = DB::transaction(function () use ($task, $repository, $outcome, $users, $attempts): CoOwnerRequest {
            $locked = YakTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $existing = $this->existingRequest($locked);

            if ($existing !== null) {
                return $existing;
            }

            $files = $outcome['files'];
            $request = CoOwnerRequest::create([
                'task_id' => $locked->id,
                'root_task_id' => $locked->rootTask()->id,
                'repository_id' => $repository->id,
                'status' => CoOwnerRequestStatus::Pending,
                'profile_source' => $outcome['source'],
                'profile_commit_sha' => $outcome['sourceSha'],
                'matched_areas' => $outcome['matchedAreas'],
                'hold_reasons' => $outcome['reasons'],
                'changed_files' => $files,
                'additions' => array_sum(array_column($files, 'additions')),
                'deletions' => array_sum(array_column($files, 'deletions')),
                'file_count' => count($files),
                'asked_user_ids' => array_map(fn ($user): int => $user->id, $users),
                'ask_attempts' => $attempts,
                'direct_messages' => [],
                'notified_areas' => [],
                'held_at' => now(),
                'expires_at' => now()->addDays(self::EXPIRY_DAYS),
            ]);

            $locked->update(['status' => TaskStatus::AwaitingCoOwner]);

            TaskLogger::info($locked, 'CI passed, held for co-owner', ['co_owner_request_id' => $request->id, 'hold_reasons' => $outcome['reasons']]);

            return $request;
        });

        $task->refresh();

        try {
            SendCoOwnerMessagesJob::dispatch($request, 'held');
        } catch (Throwable $exception) {
            report($exception);
        }

        return $request;
    }
}
