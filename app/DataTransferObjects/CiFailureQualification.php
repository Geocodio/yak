<?php

namespace App\DataTransferObjects;

use App\Models\CiJobFailure;
use Illuminate\Support\Collection;

readonly class CiFailureQualification
{
    /**
     * @param  string  $reason  default_branch, cross_branch, single_branch, different_causes, shared_parent, stale_after_green or deferred_to_flaky_scan
     * @param  Collection<int, CiJobFailure>  $evidence
     */
    public function __construct(
        public bool $qualifies,
        public string $reason,
        public Collection $evidence,
    ) {}
}
