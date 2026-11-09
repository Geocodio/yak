<?php

namespace App\Models;

use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use Carbon\CarbonImmutable;
use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A long-lived conversation: one sandbox, one runner and one chain of
 * Claude sessions shared by a root task and all of its follow-ups.
 *
 * @property ThreadStatus $status
 * @property ThreadSandboxState $sandbox_state
 * @property RunnerState $runner_state
 * @property ThreadLockHolder $lock_holder
 * @property ThreadBlockReason|null $blocked_reason
 * @property int|null $claude_account_id
 * @property CarbonImmutable|null $sandbox_state_changed_at
 * @property CarbonImmutable|null $runner_started_at
 * @property CarbonImmutable|null $lock_acquired_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $blocked_until when a usage limit resets
 */
class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'sandbox_state' => 'none',
        'runner_state' => 'none',
        'lock_holder' => 'none',
        'runner_generation' => 0,
        'claude_config_dir' => '/home/yak/.claude',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ThreadStatus::class,
            'sandbox_state' => ThreadSandboxState::class,
            'runner_state' => RunnerState::class,
            'lock_holder' => ThreadLockHolder::class,
            'blocked_reason' => ThreadBlockReason::class,
            'sandbox_state_changed_at' => 'datetime',
            'runner_started_at' => 'datetime',
            'lock_acquired_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'blocked_until' => 'datetime',
        ];
    }

    /**
     * Seconds a job should wait before retrying a turn that a usage limit holds
     * back, or null when no limit is holding the thread.
     */
    public function secondsUntilLimitResets(): ?int
    {
        if ($this->blocked_reason !== ThreadBlockReason::UsageLimit || $this->blocked_until?->isFuture() !== true) {
            return null;
        }

        return max(30, (int) ceil(now()->diffInSeconds($this->blocked_until, true)));
    }

    /**
     * @return HasMany<YakTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(YakTask::class);
    }

    /**
     * @return HasMany<ThreadSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ThreadSession::class);
    }

    /**
     * @return HasMany<ThreadRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(ThreadRepository::class);
    }

    /**
     * @return HasMany<TaskRun, $this>
     */
    public function turns(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    /**
     * @return HasOne<TaskRun, $this>
     */
    public function latestTurn(): HasOne
    {
        return $this->hasOne(TaskRun::class)->latestOfMany();
    }

    /**
     * @return HasMany<ThreadView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(ThreadView::class);
    }

    /**
     * @return BelongsTo<ClaudeAccount, $this>
     */
    public function claudeAccount(): BelongsTo
    {
        return $this->belongsTo(ClaudeAccount::class);
    }

    public function activeSession(): ?ThreadSession
    {
        return $this->sessions()->where('kind', ThreadSessionKind::Main)->orderByDesc('id')->first();
    }

    public function runningTurn(): ?TaskRun
    {
        return $this->turns()
            ->whereIn('turn_state', [TurnState::Queued, TurnState::Running])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The bearer token a client uses on this thread's session store routes. It is an HMAC of the
     * thread id under the app key, so it opens no other thread.
     */
    public function sessionStoreToken(): string
    {
        return hash_hmac('sha256', 'session-store:' . $this->getKey(), (string) config('app.key'));
    }

    public function channel(): string
    {
        return 'threads:' . $this->id;
    }

    public function isOpen(): bool
    {
        return $this->status === ThreadStatus::Open;
    }
}
