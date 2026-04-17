<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, string>|null $pr_review_path_excludes
 * @property int $pr_reviews_30d_count
 */
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'setup_status' => 'pending',
        'default_branch' => 'main',
        'pr_review_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sandbox_base_version' => 'integer',
            'pr_review_enabled' => 'boolean',
            'pr_review_path_excludes' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function githubUrl(): ?string
    {
        if (! $this->git_url) {
            return null;
        }

        return rtrim((string) preg_replace('/\.git$/', '', (string) $this->git_url), '/');
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function setupTask(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'setup_task_id');
    }

    /**
     * @return HasMany<YakTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(YakTask::class, 'repo', 'slug');
    }
}
