<?php

namespace App\Models;

use Database\Factories\CiJobFailureFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One failed CI job on one branch, recorded from a workflow_job webhook.
 * Rows from different branches that share a repo, workflow and job are
 * evidence of a cause that is not specific to any one branch.
 *
 * @property int $id
 * @property string $repo
 * @property string $workflow_path
 * @property string $workflow_name
 * @property string $job_name
 * @property string|null $failed_step
 * @property list<string>|null $test_names
 * @property string $branch
 * @property string $head_sha
 * @property int $job_id
 * @property int $run_id
 * @property string $job_url
 * @property string $actor_login
 * @property Carbon $failed_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolution green, branch_deleted, pr_closed or not_shared
 */
class CiJobFailure extends Model
{
    /** @use HasFactory<CiJobFailureFactory> */
    use HasFactory, MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'test_names' => 'array',
            'failed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<CiJobFailure>  $query
     * @return Builder<CiJobFailure>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @param  Builder<CiJobFailure>  $query
     * @return Builder<CiJobFailure>
     */
    public function scopeForJob(Builder $query, string $repo, string $workflowPath): Builder
    {
        return $query->where('repo', $repo)->where('workflow_path', $workflowPath);
    }

    /**
     * @return Builder<CiJobFailure>
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'failed_at',
            '<',
            now()->subDays((int) config('yak.ci_scan.observation_retention_days', 90)),
        );
    }

    /**
     * Whether the job runs tests, by the same name patterns the CI scan uses.
     */
    public function isTestJob(): bool
    {
        /** @var array<int, string> $excluded */
        $excluded = config('yak.ci_scan.test_job_exclude_patterns', []);

        if (Str::contains($this->job_name, $excluded, ignoreCase: true)) {
            return false;
        }

        /** @var array<int, string> $patterns */
        $patterns = config('yak.ci_scan.test_job_patterns', []);

        return Str::contains($this->job_name, $patterns, ignoreCase: true);
    }
}
