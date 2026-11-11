<?php

namespace App\Models;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use Database\Factories\ThreadRepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
