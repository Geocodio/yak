<?php

namespace App\Models;

use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only -- it does not read the `casts()` method
 * form this model uses, so without these it infers the raw column types.
 *
 * @property array<int, string>|null $pr_review_path_excludes
 * @property int $pr_reviews_30d_count
 * @property int|null $github_repo_id
 * @property string $github_full_name
 * @property array<string, mixed>|null $preview_manifest
 * @property array<string, mixed>|null $preview_env_overrides
 * @property int $current_template_version
 * @property int|null $sandbox_base_version
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $pr_review_enabled
 * @property bool $deployments_enabled
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
            'deployments_enabled' => 'boolean',
            'preview_manifest' => 'array',
            'preview_env_overrides' => 'array',
            'current_template_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The repository's current `owner/name` on GitHub.
     *
     * Distinct from `slug`, which is Yak's stable internal identity and must
     * survive a GitHub rename. Falls back to the slug for rows added before
     * the two were split apart.
     *
     * @return Attribute<string, string>
     */
    protected function githubFullName(): Attribute
    {
        return Attribute::get(fn (?string $value): string => $value ?: (string) $this->slug);
    }

    /**
     * Map an internal repo slug to that repository's current GitHub name.
     *
     * For the call sites that only carry a slug string (`tasks.repo`,
     * `pr_reviews.repo`) rather than a Repository instance. Unknown slugs
     * pass straight through, matching the pre-rename behaviour.
     */
    public static function githubNameFor(string $slug): string
    {
        $fullName = self::where('slug', $slug)->value('github_full_name');

        return is_string($fullName) && $fullName !== '' ? $fullName : $slug;
    }

    /**
     * Find the repository a GitHub webhook payload refers to.
     *
     * Resolution order matters: GitHub's numeric repo id is immutable, the
     * full name changes on rename or transfer, and the slug is only a
     * fallback for rows whose GitHub identity was never recorded.
     */
    public static function resolveFromGitHub(?int $githubRepoId, string $fullName): ?self
    {
        if ($githubRepoId !== null && $githubRepoId > 0) {
            $byId = self::where('github_repo_id', $githubRepoId)->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        if ($fullName === '') {
            return null;
        }

        return self::where('github_full_name', $fullName)->first()
            ?? self::whereNull('github_full_name')->where('slug', $fullName)->first();
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

    /**
     * @return HasMany<BranchDeployment, $this>
     */
    public function branchDeployments(): HasMany
    {
        return $this->hasMany(BranchDeployment::class);
    }
}
