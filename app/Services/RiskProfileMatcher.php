<?php

namespace App\Services;

use App\Support\CodeOwners;
use App\Support\PathMatcher;

/**
 * Places paths in risk areas the way the co-owner gate judges them. A file
 * takes its riskiest area. A glob or owner rule that PCRE cannot evaluate
 * flags the file as unchecked, and an unchecked floor glob counts as the
 * floor, so callers fail closed. One comparison and time
 * budget covers the whole run, since profile globs and CODEOWNERS come from
 * the repository.
 */
class RiskProfileMatcher
{
    public const RISK_ORDER = ['critical', 'high', 'unknown', 'medium', 'low'];

    public const HELD_RISKS = ['critical', 'high', 'unknown'];

    private const BACKTRACK_LIMIT = '100000';

    public function __construct(
        private int $maxComparisons = 2_000_000,
        private float $seconds = 5.0,
    ) {}

    /**
     * @param  list<string>  $paths
     * @param  list<array{name: string, risk: string, paths: list<string>}>  $areas
     * @param  list<string>  $floorPaths
     * @return array{files: array<string, array{areas: list<int>, area: ?int, isFloor: bool, owners: list<string>, isUnchecked: bool, globHits: list<array{0: int, 1: int}>}>, isCapped: bool}
     */
    public function classify(array $paths, array $areas, array $floorPaths, ?CodeOwners $codeOwners = null, bool $withGlobHits = false): array
    {
        $previousLimit = ini_set('pcre.backtrack_limit', self::BACKTRACK_LIMIT);
        $startedAt = hrtime(true);
        $comparisons = 0;
        $isOverBudget = function (int $cost = 1) use (&$comparisons, $startedAt): bool {
            $comparisons += $cost;

            return $comparisons > $this->maxComparisons || hrtime(true) - $startedAt >= $this->seconds * 1e9;
        };
        $files = [];

        try {
            $areaMatchers = array_map(fn (array $area): array => array_map(PathMatcher::compile(...), $area['paths']), $areas);
            $floorMatchers = array_map(PathMatcher::compile(...), $floorPaths);
            $rank = array_flip(self::RISK_ORDER);
            $ownerCost = max(1, $codeOwners?->ruleCount() ?? 0);

            foreach ($paths as $path) {
                if ($isOverBudget()) {
                    return ['files' => $files, 'isCapped' => true];
                }

                $isUnchecked = false;
                $matched = [];
                $globHits = [];

                foreach ($areaMatchers as $index => $matchers) {
                    foreach ($matchers as $globIndex => $matcher) {
                        if ($isOverBudget()) {
                            return ['files' => $files, 'isCapped' => true];
                        }

                        $result = $matcher($path);

                        if ($result === null) {
                            $isUnchecked = true;
                        }

                        if ($result !== true) {
                            continue;
                        }

                        if ($matched === [] || end($matched) !== $index) {
                            $matched[] = $index;
                        }

                        if (! $withGlobHits) {
                            break;
                        }

                        $globHits[] = [$index, $globIndex];
                    }
                }

                $isFloor = false;
                foreach ($floorMatchers as $matcher) {
                    if ($isOverBudget()) {
                        return ['files' => $files, 'isCapped' => true];
                    }

                    $result = $matcher($path);

                    if ($result !== false) {
                        $isFloor = true;
                        $isUnchecked = $isUnchecked || $result === null;

                        break;
                    }
                }

                $riskiest = null;
                foreach ($matched as $index) {
                    if ($riskiest === null || $rank[$areas[$index]['risk']] < $rank[$areas[$riskiest]['risk']]) {
                        $riskiest = $index;
                    }
                }

                $owners = [];
                if ($codeOwners !== null && ($riskiest !== null || $isFloor || $isUnchecked)) {
                    if ($isOverBudget($ownerCost)) {
                        return ['files' => $files, 'isCapped' => true];
                    }

                    $owners = $codeOwners->ownersFor($path);

                    if ($owners === null) {
                        $owners = [];
                        $isUnchecked = true;
                    }
                }

                $files[$path] = ['areas' => $matched, 'area' => $riskiest, 'isFloor' => $isFloor, 'owners' => $owners, 'isUnchecked' => $isUnchecked, 'globHits' => $globHits];
            }

            return ['files' => $files, 'isCapped' => false];
        } finally {
            if ($previousLimit !== false) {
                ini_set('pcre.backtrack_limit', $previousLimit);
            }
        }
    }
}
