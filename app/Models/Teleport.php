<?php

namespace App\Models;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\TeleportStep;
use Carbon\CarbonImmutable;
use Database\Factories\TeleportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover of a thread between Yak and a device. Ownership moves only when it completes.
 *
 * @property string $id
 * @property int $thread_id
 * @property int $device_id
 * @property int $user_id
 * @property TeleportDirection $direction
 * @property TeleportState $state
 * @property string|null $session_id
 * @property array<string, mixed>|null $payload
 * @property string|null $running_turn
 * @property string|null $visibility_choice
 * @property string|null $message
 * @property list<array{step: string, status: string}> $progress
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $manifest
 * @property array<string, string>|null $memory_snapshot
 * @property array<string, string>|null $branch_heads
 * @property CarbonImmutable|null $last_call_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Teleport extends Model
{
    /** @use HasFactory<TeleportFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'open',
        'progress' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TeleportDirection::class,
            'state' => TeleportState::class,
            'payload' => 'array',
            'progress' => 'array',
            'manifest' => 'array',
            'memory_snapshot' => 'array',
            'branch_heads' => 'array',
            'last_call_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Teleport $teleport): void {
            $teleport->expires_at ??= now()->addMinutes((int) config('yak.teleport.expiry_minutes'));
        });
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressStep(TeleportStep $step, string $status): void
    {
        $progress = $this->progress;
        $found = false;

        foreach ($progress as $index => $entry) {
            if ($entry['step'] === $step->value) {
                $progress[$index]['status'] = $status;
                $found = true;
            }
        }

        if (! $found) {
            $progress[] = ['step' => $step->value, 'status' => $status];
        }

        $this->forceFill(['progress' => $progress])->save();
    }

    public function touchCall(): void
    {
        $this->forceFill([
            'last_call_at' => now(),
            'expires_at' => now()->addMinutes((int) config('yak.teleport.expiry_minutes')),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'teleport_id' => $this->id,
            'thread_id' => $this->thread_id,
            'direction' => $this->direction->value,
            'state' => $this->state->value,
            'device_id' => $this->device_id,
            'created_at' => $this->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $this->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'poll_after_seconds' => 2,
            'progress' => $this->progress,
            'failure' => $this->failure_code === null ? null : ['code' => $this->failure_code, 'message' => $this->failure_message],
        ];
    }
}
