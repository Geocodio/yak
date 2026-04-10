<?php

namespace App\Models;

use Database\Factories\DailyCostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyCost extends Model
{
    /** @use HasFactory<DailyCostFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_usd' => 'decimal:4',
            'updated_at' => 'datetime',
        ];
    }
}
