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
     * Roles an artifact can carry (spec §8). `cut`, `thumbnail`,
     * `screenshot`, `raw` and `manifest` are written today; the rest are
     * reserved for the v3 shoot/render phases.
     *
     * @var list<string>
     */
    public const array ROLES = [
        'cut', 'thumbnail', 'preview', 'chapters', 'shot',
        'still', 'screenshot', 'voiceover', 'manifest', 'script', 'raw',
    ];

    /**
     * Derive `role` for rows written before the column existed. Director's
     * Cut artifacts are deliberately left null so they never surface in
     * the `cut()` scope — the tier is gone and its output was never used.
     */
    public static function backfillRoles(): void
    {
        static::query()->whereNull('role')->chunkById(500, function ($artifacts): void {
            foreach ($artifacts as $artifact) {
                $role = self::roleFor((string) $artifact->type, (string) $artifact->filename);

                if ($role !== null) {
                    $artifact->newQuery()->whereKey($artifact->getKey())->update(['role' => $role]);
                }
            }
        });
    }

    public static function roleFor(string $type, string $filename): ?string
    {
        if (str_contains($filename, 'director-cut')) {
            return null;
        }

        return match ($type) {
            'video_cut' => 'cut',
            'video_thumbnail' => 'thumbnail',
            'screenshot' => 'screenshot',
            'video' => 'raw',
            'file' => $filename === 'storyboard.json' ? 'manifest' : null,
            default => null,
        };
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

    /**
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeDirectorCut(Builder $query): Builder
    {
        return $query->where('type', 'video_cut')->where('filename', 'like', '%director-cut%');
    }

    /**
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeReviewerThumbnail(Builder $query): Builder
    {
        return $query->where('type', 'video_thumbnail')->where('filename', 'like', '%reviewer-cut%');
    }
}
