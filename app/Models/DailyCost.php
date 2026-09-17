<?php

namespace App\Models;

use Database\Factories\DailyCostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

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
     * Add one agent run's cost to today's total. `$newTask` is true for the
     * first run of a task (initial, research, review, setup, follow-up
     * child) and false for a CI retry or clarification reply on a task
     * already counted, so task_count means tasks rather than runs.
     *
     * Increment-then-insert keeps the update atomic under concurrent
     * workers; the read-modify-write it replaces lost updates that landed
     * between the read and the write.
     */
    public static function accumulate(float $costUsd, bool $newTask = true): void
    {
        $today = now()->toDateString();

        if (self::applyToToday($today, $costUsd, $newTask)) {
            return;
        }

        try {
            self::query()->insert([
                'date' => $today,
                'total_usd' => $costUsd,
                'task_count' => $newTask ? 1 : 0,
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another worker inserted today's row between the update and
            // the insert; apply this run to it instead.
            self::applyToToday($today, $costUsd, $newTask);
        }
    }

    /**
     * Atomic increment of today's row. False when there is no row yet.
     */
    private static function applyToToday(string $today, float $costUsd, bool $newTask): bool
    {
        $updated = self::query()
            ->whereDate('date', $today)
            ->increment('total_usd', $costUsd, ['updated_at' => now()]);

        if ($updated === 0) {
            return false;
        }

        if ($newTask) {
            self::query()->whereDate('date', $today)->increment('task_count');
        }

        return true;
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
