<?php

namespace App\Models;

use App\Enums\CoOwnerRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CoOwnerRequestFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change held for a co-owner before Yak opens its pull request.
 *
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only.
 *
 * @property CoOwnerRequestStatus $status
 * @property array<int, array{name: string, risk: string, rationale: string, paths: array<int, string>}> $matched_areas
 * @property array<int, string> $hold_reasons
 * @property array<int, array{path: string, previousPath: string|null, status: 'new'|'modified'|'removed'|'renamed', additions: int, deletions: int, isHeld: bool}> $changed_files
 * @property array<int, int> $asked_user_ids
 * @property array<int, array{entry: string, kind: string, outcome: string}> $ask_attempts
 * @property array<int, array{userId: int, channel: string, ts: string}> $direct_messages
 * @property array<int, string> $notified_areas
 * @property CarbonImmutable $held_at
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $decided_at
 * @property-read YakTask $task
 * @property-read Repository $repository
 * @property-read User|null $decidedBy
 */
class CoOwnerRequest extends Model
{
    /** @use HasFactory<CoOwnerRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => CoOwnerRequestStatus::class,
            'matched_areas' => 'array',
            'hold_reasons' => 'array',
            'changed_files' => 'array',
            'asked_user_ids' => 'array',
            'ask_attempts' => 'array',
            'direct_messages' => 'array',
            'notified_areas' => 'array',
            'held_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'task_id');
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return Collection<int, User>
     */
    public function askedUsers(): Collection
    {
        return User::whereIn('id', $this->asked_user_ids)->get();
    }
}
