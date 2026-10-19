<?php

namespace App\Services;

use App\Support\CodeOwners;
use App\Support\PathMatcher;

/**
 * Places paths in risk areas the way the co-owner gate judges them. A file
 * takes its riskiest area. A glob that PCRE cannot evaluate is reported as
 * could not check and never counts as a match. One comparison and time
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
     * @return array{files: array<string, array{areas: list<int>, area: ?int, isFloor: bool, owners: list<string>, globHits: list<array{0: int, 1: int}>}>, isCapped: bool}
     */
    public function classify(array $paths, array $areas, array $floorPaths, ?CodeOwners $codeOwners = null, bool $withGlobHits = false): array
    {
        $previousLimit = ini_set('pcre.backtrack_limit', self::BACKTRACK_LIMIT);
        $startedAt = hrtime(true);
        $comparisons = 0;
        $files = [];

        try {
            $areaMatchers = array_map(fn (array $area): array => array_map(PathMatcher::compile(...), $area['paths']), $areas);
            $floorMatchers = array_map(PathMatcher::compile(...), $floorPaths);
            $rank = array_flip(self::RISK_ORDER);
            $ownerCost = max(1, $codeOwners?->ruleCount() ?? 0);

            foreach ($paths as $path) {
                $matched = [];
                $globHits = [];

                foreach ($areaMatchers as $index => $matchers) {
                    foreach ($matchers as $globIndex => $matcher) {
                        if (++$comparisons > $this->maxComparisons || hrtime(true) - $startedAt >= $this->seconds * 1e9) {
                            return ['files' => $files, 'isCapped' => true];
                        }

                        if ($matcher($path) !== true) {
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
                    $comparisons++;
                    if ($matcher($path) === true) {
                        $isFloor = true;

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
                if ($codeOwners !== null && ($riskiest !== null || $isFloor)) {
                    $comparisons += $ownerCost;
                    $owners = $codeOwners->ownersFor($path);
                }

                $files[$path] = ['areas' => $matched, 'area' => $riskiest, 'isFloor' => $isFloor, 'owners' => $owners, 'globHits' => $globHits];
            }

            return ['files' => $files, 'isCapped' => false];
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }
    }
}
