<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\Http\Resources\RiskProfilePageData;
use App\Models\Repository;
use App\Support\CodeOwners;
use Throwable;

/**
 * Replays a PR's or branch's file list against the effective risk profile and
 * says whether the co-owner gate would hold it. Read-only: GitHub GETs only.
 * The input is untrusted, so it is validated here and encoded in every URL.
 *
 * @phpstan-type AreaRef array{index: int, name: string, risk: string}
 */
class RiskProfileChangeCheck
{
    private const MAX_FILES = 200;

    private const INVALID = 'Enter a PR number or a branch name to test.';

    public function __construct(private AppService $github, private RiskProfileMatcher $matcher) {}

    /**
     * holdReason explains a hold that has no held area: the floor, files Yak
     * could not check, or a cap.
     *
     * @return array{
     *     input: string,
     *     verdict: 'held'|'normal'|'invalid'|'not-found'|'error'|'no-profile',
     *     kind: 'pr'|'branch'|null,
     *     label: string,
     *     url: ?string,
     *     message: ?string,
     *     files: list<array{path: string, previousPath: ?string, area: AreaRef|null, isFloor: bool, isUnchecked: bool, isHeld: bool}>,
     *     heldAreas: list<AreaRef>,
     *     isFloorHeld: bool,
     *     asks: list<string>,
     *     capReason: 'listing'|'budget'|null,
     *     capMessage: ?string,
     *     holdReason: ?string,
     *     gateMode: 'off'|'enforce',
     * }
     */
    public function run(Repository $repository, string $input): array
    {
        $input = trim($input);
        $result = [
            'input' => $input, 'verdict' => 'invalid', 'kind' => null, 'label' => $input, 'url' => null, 'message' => null,
            'files' => [], 'heldAreas' => [], 'isFloorHeld' => false, 'asks' => [], 'capReason' => null, 'capMessage' => null, 'holdReason' => null,
            'gateMode' => $repository->settings()->coOwnerGateMode() === 'enforce' ? 'enforce' : 'off',
        ];

        if (preg_match('/^#?(\d{1,9})$/', $input, $matches) === 1) {
            $kind = 'pr';
            $reference = (int) $matches[1];
            $result['label'] = '#' . $reference;
        } elseif ($this->isBranchName($input)) {
            $kind = 'branch';
            $reference = $input;
        } else {
            return [...$result, 'message' => self::INVALID];
        }

        $profile = RiskProfilePageData::effectiveProfile($repository);

        if ($profile === null) {
            return [...$result, 'verdict' => 'no-profile', 'kind' => $kind];
        }

        $result['kind'] = $kind;
        $fullName = (string) $repository->github_full_name;
        $installationId = (int) config('yak.channels.github.installation_id');
        $githubUrl = $repository->githubUrl();

        try {
            if ($kind === 'pr') {
                $pullRequest = $this->github->getPullRequestOrFail($installationId, $fullName, $reference);

                if (! isset($pullRequest['number'])) {
                    return [...$result, 'verdict' => 'not-found', 'message' => "PR #{$reference} was not found in {$fullName}. The number may be wrong, or the PR may belong to another repository. Only PRs and branches in this repository can be tested. Nothing was saved."];
                }

                $result['url'] = is_string($pullRequest['html_url'] ?? null) ? $pullRequest['html_url'] : null;
                $changed = $this->github->listPullRequestFilesOrFail($installationId, $fullName, $reference);
                $listingCap = 3000;
                $listingMessage = 'GitHub lists at most 3,000 files for a PR, so Yak cannot see every file and would hold this change.';
            } else {
                $changed = $this->github->compareFilesOrFail($installationId, $fullName, (string) $repository->default_branch, $reference);

                if ($changed === null) {
                    return [...$result, 'verdict' => 'not-found', 'message' => "Branch {$reference} was not found in {$fullName}. Only PRs and branches in this repository can be tested. Nothing was saved."];
                }

                $result['url'] = $githubUrl === null ? null : $githubUrl . '/compare/' . AppService::encodeRef((string) $repository->default_branch) . '...' . AppService::encodeRef($reference);
                $listingCap = 300;
                $listingMessage = 'GitHub compares at most 300 files, so Yak cannot see every file and would hold this change.';
            }

            $sha = app(RepositoryConfig::class)->snapshot($repository)->commitSha;
            $codeOwners = $sha === null ? CodeOwners::empty() : RiskProfilePageData::codeOwners($repository, $sha);
        } catch (Throwable $exception) {
            report($exception);

            return [...$result, 'verdict' => 'error', 'message' => 'Could not read the change from GitHub. Try again.'];
        }

        $entries = [];
        foreach ($changed as $file) {
            $path = (string) ($file['filename'] ?? '');
            if ($path !== '') {
                $entries[] = [$path, isset($file['previous_filename']) ? (string) $file['previous_filename'] : null];
            }
        }

        $paths = array_values(array_unique(array_merge(...array_map(fn (array $entry): array => array_values(array_filter($entry, fn (?string $path): bool => $path !== null && $path !== '')), $entries ?: [[]]))));
        $areas = array_values((array) ($profile['areas'] ?? []));
        $classified = $this->matcher->classify($paths, $areas, array_values(array_map('strval', (array) config('yak.pr_review.config_floor_paths'))), $codeOwners);
        $rank = array_flip(RiskProfileMatcher::RISK_ORDER);
        $areaRef = fn (int $index): array => ['index' => $index, 'name' => (string) $areas[$index]['name'], 'risk' => (string) $areas[$index]['risk']];

        $files = [];
        $heldAreas = [];
        $ownerCounts = [];
        $isFloorHeld = false;
        $isUncheckedHeld = false;

        foreach ($entries as [$path, $previousPath]) {
            $parts = array_filter([$classified['files'][$path] ?? null, $previousPath === null ? null : ($classified['files'][$previousPath] ?? null)]);
            $areaIndex = null;
            foreach ($parts as $part) {
                if ($part['area'] !== null && ($areaIndex === null || $rank[$areas[$part['area']]['risk']] < $rank[$areas[$areaIndex]['risk']])) {
                    $areaIndex = $part['area'];
                }
            }

            $isFloor = (bool) array_filter($parts, fn (array $part): bool => $part['isFloor']);
            $isUnchecked = (bool) array_filter($parts, fn (array $part): bool => $part['isUnchecked']);
            $isHeld = $isFloor || $isUnchecked || ($areaIndex !== null && in_array($areas[$areaIndex]['risk'], RiskProfileMatcher::HELD_RISKS, true));

            if ($isHeld) {
                foreach ($parts as $part) {
                    foreach ($part['owners'] as $owner) {
                        $ownerCounts[$owner] = ($ownerCounts[$owner] ?? 0) + 1;
                    }
                }
                if ($areaIndex !== null && in_array($areas[$areaIndex]['risk'], RiskProfileMatcher::HELD_RISKS, true)) {
                    $heldAreas[$areaIndex] = $areaRef($areaIndex);
                }
            }

            $isFloorHeld = $isFloorHeld || $isFloor;
            $isUncheckedHeld = $isUncheckedHeld || $isUnchecked;
            $files[] = ['path' => $path, 'previousPath' => $previousPath, 'area' => $areaIndex === null ? null : $areaRef($areaIndex), 'isFloor' => $isFloor, 'isUnchecked' => $isUnchecked, 'isHeld' => $isHeld];
        }

        usort($files, fn (array $a, array $b): int => [! $a['isHeld'], $a['path']] <=> [! $b['isHeld'], $b['path']]);
        usort($heldAreas, fn (array $a, array $b): int => [$rank[$a['risk']], $a['index']] <=> [$rank[$b['risk']], $b['index']]);
        arsort($ownerCounts);

        $capReason = $classified['isCapped'] ? 'budget' : (count($changed) >= $listingCap ? 'listing' : null);
        $isHeld = $heldAreas !== [] || $isFloorHeld || $isUncheckedHeld || $capReason !== null;
        $capMessage = match ($capReason) {
            'listing' => $listingMessage,
            'budget' => 'Yak could not check every file against the profile in time, so it would hold this change.',
            default => null,
        };
        $holdReason = null;

        if ($isHeld && $heldAreas === []) {
            $holdReason = match (true) {
                $isFloorHeld => "This change touches Yak's own config files, so Yak always holds it.",
                $isUncheckedHeld => 'Yak could not check some files against the profile, so it would hold this change.',
                default => $capMessage,
            };
        }

        return [
            ...$result,
            'verdict' => $isHeld ? 'held' : 'normal',
            'files' => array_slice($files, 0, self::MAX_FILES),
            'heldAreas' => $heldAreas,
            'isFloorHeld' => $isFloorHeld,
            'asks' => array_map('strval', array_keys($ownerCounts)),
            'capReason' => $capReason,
            'capMessage' => $capMessage,
            'holdReason' => $holdReason,
        ];
    }

    private function isBranchName(string $input): bool
    {
        return preg_match('~^[A-Za-z0-9._/-]{1,255}$~', $input) === 1
            && ! str_contains($input, '..')
            && ! str_starts_with($input, '/')
            && ! str_starts_with($input, '-')
            && ! str_ends_with($input, '/');
    }
}
