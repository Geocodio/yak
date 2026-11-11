<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DatasetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The one dataset a repository declares in `.yak/config.yml`, held as one
 * Incus custom volume with a snapshot per version.
 *
 * @property int $id
 * @property int $repository_id
 * @property string $name
 * @property string $source_prefix
 * @property string $version_key
 * @property string $mount_path
 * @property int|null $declared_size_gb
 * @property int|null $size_cap_gb
 * @property string $volume_name
 * @property DatasetStatus $status
 * @property int|null $current_version_id
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_fetched_at
 * @property-read Repository $repository
 */
class Dataset extends Model
{
    /** @use HasFactory<DatasetFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'absent'];

    /**
     * Incus volume name for a repository's dataset; built from slug output only.
     */
    public static function volumeNameFor(Repository $repository): string
    {
        return 'yak-data-' . Str::slug((string) $repository->slug);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DatasetStatus::class,
            'last_fetched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return HasMany<DatasetVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DatasetVersion::class);
    }

    /** @return BelongsTo<DatasetVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DatasetVersion::class, 'current_version_id');
    }
}
