<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only.
 *
 * @property mixed $data
 * @property array{number: int, title: string, url: string}|null $error_pull_request
 */
class RepositoryConfigFile extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'json',
            'error_pull_request' => 'array',
        ];
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
