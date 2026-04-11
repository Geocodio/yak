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

    public static function accumulate(float $costUsd): void
    {
        $today = now()->toDateString();

        $dailyCost = self::whereDate('date', $today)->first();

        if ($dailyCost === null) {
            self::query()->insert([
                'date' => $today,
                'total_usd' => $costUsd,
                'task_count' => 1,
                'updated_at' => now(),
            ]);

            return;
        }

        $dailyCost->update([
            'total_usd' => (float) $dailyCost->total_usd + $costUsd,
            'task_count' => $dailyCost->task_count + 1,
            'updated_at' => now(),
        ]);
    }

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
