<?php

namespace App\Models;

use App\Enums\DiffFileStatus;
use Database\Factories\ThreadDiffFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One changed file of a thread diff. Binary and oversized files carry no patch.
 *
 * @property int $thread_diff_id
 * @property int|null $thread_diff_repository_id null means the primary repository
 * @property string $path
 * @property string|null $old_path
 * @property DiffFileStatus $status
 * @property int|null $additions null for a binary file
 * @property int|null $deletions null for a binary file
 * @property bool $is_binary
 * @property bool $is_too_large
 * @property string|null $patch
 */
class ThreadDiffFile extends Model
{
    /** @use HasFactory<ThreadDiffFileFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DiffFileStatus::class,
            'is_binary' => 'boolean',
            'is_too_large' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ThreadDiff, $this>
     */
    public function diff(): BelongsTo
    {
        return $this->belongsTo(ThreadDiff::class, 'thread_diff_id');
    }

    /**
     * @return BelongsTo<ThreadDiffRepository, $this>
     */
    public function repositoryDiff(): BelongsTo
    {
        return $this->belongsTo(ThreadDiffRepository::class, 'thread_diff_repository_id');
    }
}
