<?php

namespace App\Models;

use Database\Factories\ArtifactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Artifact extends Model
{
    /** @use HasFactory<ArtifactFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    public function signedUrl(int $expiryDays = 7): string
    {
        return URL::temporarySignedRoute(
            'artifacts.show',
            now()->addDays($expiryDays),
            ['task' => $this->yak_task_id, 'filename' => $this->filename]
        );
    }

    /**
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeVideoCuts(Builder $query): Builder
    {
        return $query->where('type', 'video_cut');
    }

    /**
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeReviewerCut(Builder $query): Builder
    {
        return $query->where('type', 'video_cut')->where('filename', 'like', '%reviewer-cut%');
    }
}
