<?php

namespace App\Models;

use App\Enums\RepositoryRole;
use Database\Factories\ThreadDiffRepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The diff of one repository of a multi-repo thread.
 *
 * @property int $thread_diff_id
 * @property string $repo
 * @property RepositoryRole $role
 * @property string $base_sha
 * @property string $head_sha
 * @property int $files_count
 * @property int $additions
 * @property int $deletions
 * @property bool $is_throwaway
 */
class ThreadDiffRepository extends Model
{
    /** @use HasFactory<ThreadDiffRepositoryFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => RepositoryRole::class, 'is_throwaway' => 'boolean'];
    }

    /**
     * @return BelongsTo<ThreadDiff, $this>
     */
    public function diff(): BelongsTo
    {
        return $this->belongsTo(ThreadDiff::class, 'thread_diff_id');
    }

    /**
     * @return HasMany<ThreadDiffFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ThreadDiffFile::class, 'thread_diff_repository_id')->orderBy('path');
    }
}
