<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RiskProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only -- it does not read the `casts()` method
 * form this model uses, so without these it infers the raw column types.
 *
 * @property array<string, mixed> $profile
 * @property CarbonImmutable|null $approved_at
 */
class RiskProfile extends Model
{
    /** @use HasFactory<RiskProfileFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'approved_at' => 'datetime',
        ];
    }
}
