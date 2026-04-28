<?php

namespace App\Services;

use App\DataTransferObjects\ParsedPriorFinding;

class PriorFindingsRollup
{
    /**
     * @param  array<int, ParsedPriorFinding>  $priorFindings
     */
    public function render(array $priorFindings): string
    {
        if ($priorFindings === []) {
            return '';
        }

        $counts = [
            ParsedPriorFinding::STATUS_FIXED => 0,
            ParsedPriorFinding::STATUS_STILL_OUTSTANDING => 0,
            ParsedPriorFinding::STATUS_UNTOUCHED => 0,
            ParsedPriorFinding::STATUS_WITHDRAWN => 0,
        ];

        foreach ($priorFindings as $finding) {
            $counts[$finding->status]++;
        }

        $total = array_sum($counts);
        if ($total > 0 && $counts[ParsedPriorFinding::STATUS_FIXED] === $total) {
            return '**Status of prior findings:** All prior concerns addressed.';
        }

        $labels = [
            ParsedPriorFinding::STATUS_FIXED => 'fixed',
            ParsedPriorFinding::STATUS_STILL_OUTSTANDING => 'still outstanding',
            ParsedPriorFinding::STATUS_UNTOUCHED => 'untouched',
            ParsedPriorFinding::STATUS_WITHDRAWN => 'withdrawn',
        ];

        $parts = [];
        foreach ($labels as $status => $label) {
            if ($counts[$status] > 0) {
                $parts[] = "{$counts[$status]} {$label}";
            }
        }

        return '**Status of prior findings:** ' . implode(', ', $parts) . '. See thread replies for detail.';
    }
}
