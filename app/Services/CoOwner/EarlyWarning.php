<?php

namespace App\Services\CoOwner;

use App\Http\Resources\RiskProfilePageData;
use App\Models\YakTask;
use App\Support\PersonName;

/**
 * Decides whether a new task deserves an early hint that a co-owner may need
 * to approve the change. The hint fails open: any doubt means no warning.
 */
class EarlyWarning
{
    public function __construct(
        private CoOwnerGate $gate,
        private TaskRequester $requesters,
        private GitHubWriteAccess $writeAccess,
        private RiskAreaGuesser $guesser,
    ) {}

    /**
     * @return array{areas: list<array{name: string, risk: string}>, repoName: string, fallbackName: ?string}|null
     */
    public function evaluate(YakTask $task): ?array
    {
        $repository = $task->repository;
        $settings = $repository?->settings();

        if ($repository === null
            || $settings === null
            || $task->parent_task_id !== null
            || $settings->coOwnerGateMode() !== 'enforce'
            || $this->gate->isExempt($task)) {
            return null;
        }

        $requester = $this->requesters->for($task);
        if ($requester !== null && in_array($this->writeAccess->check($repository, $requester), ['write', 'error'], true)) {
            return null;
        }

        $profile = RiskProfilePageData::effectiveProfile($repository, $settings);
        if ($profile === null) {
            return null;
        }

        /** @var list<array{name: string, risk: string, rationale: string}> $profileAreas */
        $profileAreas = array_values((array) ($profile['areas'] ?? []));
        $guessed = $this->guesser->guess((string) $task->description, $profileAreas);

        $areas = [];
        foreach ($profileAreas as $area) {
            if (in_array($area['name'], $guessed, true)) {
                $areas[] = ['name' => $area['name'], 'risk' => $area['risk']];
            }
        }

        if ($areas === []) {
            return null;
        }

        $fallbackName = $repository->defaultResponsibleUser?->name;

        return [
            'areas' => $areas,
            'repoName' => (string) $repository->github_full_name,
            'fallbackName' => $fallbackName === null ? null : PersonName::first($fallbackName),
        ];
    }
}
