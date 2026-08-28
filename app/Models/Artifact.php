<?php

namespace App\Models;

use Database\Factories\ArtifactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        static::query()->whereNull('role')->chunkById(500, function (EloquentCollection $artifacts): void {
            foreach ($artifacts as $artifact) {
                $role = self::roleFor((string) $artifact->type, (string) $artifact->filename);

                if ($role !== null) {
                    $artifact->newQuery()->whereKey($artifact->getKey())->update(['role' => $role]);
                }
            }
        });
    }

    /**
     * Map an artifact's `type` and filename onto its role, or null when the
     * row carries no role (Director's Cut output and unrecognised files).
     */
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
     * Artifacts are looked up by role, not filename: cuts rendered before
     * v3 are still called `reviewer-cut.mp4` on disk.
     *
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    /**
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeCut(Builder $query): Builder
    {
        return $query->where('role', 'cut');
    }

    /**
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeThumbnail(Builder $query): Builder
    {
        return $query->where('role', 'thumbnail');
    }

    /**
     * Raw agent footage: the legacy `walkthrough.webm` and, from phase 2,
     * per-shot clips are `shot` instead.
     *
     * @param  Builder<Artifact>  $query
     * @return Builder<Artifact>
     */
    public function scopeRawFootage(Builder $query): Builder
    {
        return $query->where('role', 'raw');
    }
}
