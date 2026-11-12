<?php

namespace App\Models;

use App\Enums\DatasetVersionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DatasetVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One fetched version of a dataset, backed by a snapshot of the dataset volume.
 *
 * @property int $id
 * @property int $dataset_id
 * @property string $version
 * @property DatasetVersionStatus $status
 * @property CarbonImmutable|null $fetched_at
 * @property CarbonImmutable|null $retired_at
 * @property-read Dataset $dataset
 */
class DatasetVersion extends Model
{
    /** @use HasFactory<DatasetVersionFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'fetching'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DatasetVersionStatus::class,
            'fetched_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Dataset, $this> */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /** @return HasMany<DatasetClone, $this> */
    public function clones(): HasMany
    {
        return $this->hasMany(DatasetClone::class);
    }

    /** @return HasMany<DatasetClone, $this> */
    public function liveClones(): HasMany
    {
        return $this->clones()->whereNull('destroyed_at');
    }
}
