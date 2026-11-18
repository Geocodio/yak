<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CiDefaultBranchGreenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * When a job last passed on the default branch. Keyed by workflow and job
 * name rather than workflow path, because a job's success event carries the
 * names only.
 *
 * @property int $id
 * @property string $repo
 * @property string $workflow_name
 * @property string $job_name
 * @property Carbon $completed_at
 */
class CiDefaultBranchGreen extends Model
{
    /** @use HasFactory<CiDefaultBranchGreenFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public static function latestFor(string $repo, string $workflowName, string $jobName): ?CarbonImmutable
    {
        $completedAt = static::query()
            ->where('repo', $repo)
            ->where('workflow_name', $workflowName)
            ->where('job_name', $jobName)
            ->value('completed_at');

        return $completedAt === null ? null : CarbonImmutable::parse($completedAt);
    }
}
