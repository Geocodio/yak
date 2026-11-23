<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved override for one config key. A missing row means the config or env value applies.
 *
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
