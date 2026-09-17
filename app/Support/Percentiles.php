<?php

namespace App\Support;

/**
 * Nearest-rank percentiles computed in PHP so the same code runs on
 * MariaDB in production and SQLite in tests. Inputs are small (one
 * value per run or task in the selected period).
 */
final class Percentiles
{
    /**
     * @param  array<int, int|float|null>  $values  Nulls (unknown durations) are dropped.
     * @return array{p50: int|null, p90: int|null, p99: int|null, n: int}
     */
    public static function of(array $values): array
    {
        $values = array_values(array_filter($values, fn ($v): bool => $v !== null));
        sort($values);
        $n = count($values);

        if ($n === 0) {
            return ['p50' => null, 'p90' => null, 'p99' => null, 'n' => 0];
        }

        $pick = function (float $p) use ($values, $n): int {
            $rank = (int) max(0, min($n - 1, ceil($p * $n) - 1));

            return (int) round($values[$rank]);
        };

        return ['p50' => $pick(0.5), 'p90' => $pick(0.9), 'p99' => $pick(0.99), 'n' => $n];
    }
}
