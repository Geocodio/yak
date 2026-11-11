<?php

namespace App\Models;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use Database\Factories\ThreadRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property int|null $pull_request_number
 * @property string|null $pull_request_state
 * @property string|null $pull_request_title
 * @property Carbon|null $pull_request_closed_at
 * @property int $ci_retry_count
 * @property string|null $ci_status
 * @property array<int, array{name: string, state: string, durationSeconds?: int|null, excerpt?: string|null}>|null $ci_checks
 * @property string|null $last_commit_sha
 * @property string|null $merge_reason
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
            'ci_checks' => 'array',
            'pull_request_closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function hasOpenPullRequest(): bool
    {
        return $this->pull_request_state === 'open';
    }

    public function hasEnded(): bool
    {
        return in_array($this->pull_request_state, ['merged', 'closed'], true);
    }

    public function isChangesRepo(): bool
    {
        return $this->role !== RepositoryRole::Context;
    }

    /**
     * @param  Builder<ThreadRepository>  $query
     */
    #[Scope]
    protected function openPullRequests(Builder $query): void
    {
        $query->where('pull_request_state', 'open');
    }
}
