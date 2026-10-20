<?php

namespace App\Services;

use App\Support\CodeOwners;

/**
 * Decides whether a change is held for a co-owner: the one verdict shared by
 * the "test against a change" helper and the gate.
 *
 * @phpstan-type AreaRef array{index: int, name: string, risk: string}
 */
class RiskProfileVerdict
{
    public function __construct(private RiskProfileMatcher $matcher) {}

    /**
     * Every file is returned, held first, so callers choose how many to show.
     *
     * @param  list<array<string, mixed>>  $changed  Files as GitHub lists them: filename and, for a rename, previous_filename.
     * @param  list<array{name: string, risk: string, paths: list<string>}>  $areas
     * @return array{
     *     isHeld: bool,
     *     files: list<array{path: string, previousPath: ?string, area: AreaRef|null, isFloor: bool, isUnchecked: bool, isHeld: bool}>,
     *     heldAreas: list<AreaRef>,
     *     isFloorHeld: bool,
     *     isUncheckedHeld: bool,
     *     asks: list<string>,
     *     capReason: 'listing'|'budget'|null,
     *     capMessage: ?string,
     *     holdReason: ?string,
     * }
     */
    public function evaluate(array $changed, array $areas, CodeOwners $codeOwners, int $listingCap, string $listingMessage): array
    {
        $entries = [];
        foreach ($changed as $file) {
            $path = (string) ($file['filename'] ?? '');
            if ($path !== '') {
                $entries[] = [$path, isset($file['previous_filename']) ? (string) $file['previous_filename'] : null];
            }
        }

        $paths = array_values(array_unique(array_merge(...array_map(fn (array $entry): array => array_filter($entry, fn (?string $path): bool => $path !== null && $path !== ''), $entries ?: [[]]))));
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
            $isUnchecked = (bool) array_filter($parts, fn (array $part): bool => $part['isUnchecked']) || ($classified['isCapped'] && $parts === []);
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
                $capMessage !== null => $capMessage,
                default => 'Yak could not check some files against the profile, so it would hold this change.',
            };
        }

        return [
            'isHeld' => $isHeld,
            'files' => $files,
            'heldAreas' => $heldAreas,
            'isFloorHeld' => $isFloorHeld,
            'isUncheckedHeld' => $isUncheckedHeld,
            'asks' => array_map('strval', array_keys($ownerCounts)),
            'capReason' => $capReason,
            'capMessage' => $capMessage,
            'holdReason' => $holdReason,
        ];
    }
}
