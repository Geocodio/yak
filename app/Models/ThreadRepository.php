<?php

namespace App\Models;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ThreadRepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A repository checked out in a thread's sandbox.
 *
 * @property int $thread_id
 * @property string $repo
 * @property RepositoryRole $role
 * @property string $sandbox_path
 * @property RepositorySetupStatus $setup_status
 * @property string|null $setup_error
 * @property int|null $merge_position
 * @property string|null $branch
 * @property string|null $pull_request_url
 * @property int $ci_retry_count
 * @property string|null $last_refresh_commit
 * @property string|null $setup_output
 * @property string|null $add_reason
 * @property string|null $added_by `start`, `claude` or `teleport`
 * @property CarbonImmutable|null $setup_started_at
 */
class ThreadRepository extends Model
{
    /** @use HasFactory<ThreadRepositoryFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'primary',
        'setup_status' => 'pending',
        'ci_retry_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => RepositoryRole::class,
            'setup_status' => RepositorySetupStatus::class,
            'setup_started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function isPrimary(): bool
    {
        return $this->role === RepositoryRole::Primary;
    }

    public function isExtra(): bool
    {
        return ! $this->isPrimary();
    }

    /** The part of the slug after the owner. */
    public function shortName(): string
    {
        return Str::afterLast($this->repo, '/');
    }

    public function getsPullRequest(): bool
    {
        return in_array($this->role, [RepositoryRole::Primary, RepositoryRole::Changes], true);
    }

    /** A recorded pull request counts as open until its task is merged or closed. */
    public function hasOpenPullRequest(): bool
    {
        if ($this->pull_request_url === null) {
            return false;
        }

        $task = YakTask::query()->where('pr_url', $this->pull_request_url)->first();

        return $task === null || $task->prState() === 'open';
    }

    public static function sandboxPathFor(string $slug, RepositoryRole $role): string
    {
        return $role === RepositoryRole::Primary ? '/workspace' : '/extras/' . Str::afterLast($slug, '/');
    }
}
