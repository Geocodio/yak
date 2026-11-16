<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DatasetCloneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The copy-on-write clone of a dataset version attached to one thread sandbox
 * or preview. The row is reused when the same owner is restored or recreated.
 *
 * @property int $id
 * @property int $dataset_version_id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $volume_name
 * @property string $container_name
 * @property CarbonImmutable|null $destroyed_at
 * @property-read DatasetVersion $version
 * @property-read Thread|BranchDeployment $owner
 */
class DatasetClone extends Model
{
    /** @use HasFactory<DatasetCloneFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Incus volume name for an owner's clone; built from the model kind and numeric id only.
     */
    public static function volumeNameFor(Thread|BranchDeployment $owner): string
    {
        $kind = $owner instanceof Thread ? 'thread' : 'preview';

        return 'yak-clone-' . $kind . '-' . (int) $owner->getKey();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['destroyed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DatasetVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DatasetVersion::class, 'dataset_version_id');
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
