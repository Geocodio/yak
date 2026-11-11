<?php

namespace App\Models;

use App\Enums\RunnerState;
use App\Enums\TeleportState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Enums\TurnState;
use App\Models\Scopes\VisibleThreadScope;
use App\Services\Threads\ThreadAccess;
use Carbon\CarbonImmutable;
use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A long-lived conversation: one sandbox, one runner and one chain of Claude
 * sessions shared by a root task and all of its follow-ups. A thread with a
 * parent is a sub-thread; its root decides who may see it.
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $repo
 * @property ThreadStatus $status
 * @property ThreadVisibility $visibility
 * @property ThreadKind $kind
 * @property string|null $branch
 * @property int|null $owner_user_id
 * @property int|null $parent_thread_id
 * @property int|null $root_thread_id
 * @property ThreadSandboxState $sandbox_state
 * @property RunnerState $runner_state
 * @property int $runner_generation
 * @property ThreadLockHolder $lock_holder
 * @property ThreadOwnerSide $owner_side
 * @property ThreadBlockReason|null $blocked_reason
 * @property string|null $last_refresh_commit
 * @property string|null $wip_commit_sha
 * @property string|null $local_repo_path
 * @property string|null $git_author_name
 * @property string|null $git_author_email
 * @property CarbonImmutable|null $sandbox_state_changed_at
 * @property CarbonImmutable|null $runner_started_at
 * @property CarbonImmutable|null $lock_acquired_at
 * @property CarbonImmutable|null $blocked_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $first_teleported_at
 * @property CarbonImmutable|null $waiting_for_computer_at
 * @property string|null $teleport_requested_running_turn
 * @property CarbonImmutable|null $owner_changed_at
 * @property string|null $preview_hostname
 * @property CarbonImmutable|null $preview_last_request_at
 */
#[ScopedBy(VisibleThreadScope::class)]
class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'visibility' => 'team',
        'kind' => 'general',
        'child_awake_limit' => 3,
        'claude_config_dir' => '/home/yak/.claude',
        'sandbox_state' => 'none',
        'runner_state' => 'none',
        'runner_generation' => 0,
        'lock_holder' => 'none',
        'owner_side' => 'yak',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ThreadStatus::class,
            'visibility' => ThreadVisibility::class,
            'kind' => ThreadKind::class,
            'sandbox_state' => ThreadSandboxState::class,
            'runner_state' => RunnerState::class,
            'lock_holder' => ThreadLockHolder::class,
            'owner_side' => ThreadOwnerSide::class,
            'blocked_reason' => ThreadBlockReason::class,
            'sandbox_state_changed_at' => 'immutable_datetime',
            'runner_started_at' => 'immutable_datetime',
            'lock_acquired_at' => 'immutable_datetime',
            'blocked_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'first_teleported_at' => 'immutable_datetime',
            'waiting_for_computer_at' => 'immutable_datetime',
            'owner_changed_at' => 'immutable_datetime',
            'preview_last_request_at' => 'immutable_datetime',
        ];
    }

    public function sessionStoreToken(): string
    {
        return hash_hmac('sha256', 'session-store:' . $this->getKey(), (string) config('app.key'));
    }

    /**
     * Drops only the visibility scope, for internal code that must see hidden rows.
     *
     * @param  Builder<covariant Thread>  $query
     * @return Builder<covariant Thread>
     */
    public function scopeWithoutVisibilityScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(VisibleThreadScope::class);
    }

    /**
     * Keeps the threads the user may see; see ThreadAccess.
     *
     * @param  Builder<covariant Thread>  $query
     * @return Builder<covariant Thread>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return app(ThreadAccess::class)->scopeVisibleTo($query, $user);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function ownerDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'owner_device_id');
    }

    /**
     * @return HasMany<Teleport, $this>
     */
    public function teleports(): HasMany
    {
        return $this->hasMany(Teleport::class);
    }

    /**
     * @return HasMany<HeldThreadEvent, $this>
     */
    public function heldEvents(): HasMany
    {
        return $this->hasMany(HeldThreadEvent::class);
    }

    public function openTeleport(): ?Teleport
    {
        return $this->teleports()->whereIn('state', TeleportState::nonTerminal())->latest('id')->first();
    }

    /**
     * @return HasMany<YakTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(YakTask::class);
    }

    /**
     * Each run is one turn.
     *
     * @return HasMany<TaskRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    /**
     * The latest captured diff of the sandbox's working tree.
     *
     * @return HasOne<ThreadDiff, $this>
     */
    public function diff(): HasOne
    {
        return $this->hasOne(ThreadDiff::class);
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_thread_id');
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function root(): BelongsTo
    {
        // Unscoped: access decisions must see a root the viewer cannot.
        return $this->belongsTo(self::class, 'root_thread_id')->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Thread, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_thread_id');
    }

    /**
     * People a private root thread is shared with.
     *
     * @return HasMany<ThreadShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(ThreadShare::class);
    }

    /**
     * @return HasMany<ThreadRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(ThreadRepository::class);
    }

    /**
     * @return HasOne<ThreadRepository, $this>
     */
    public function primaryRepository(): HasOne
    {
        return $this->hasOne(ThreadRepository::class)->where('role', 'primary');
    }

    /**
     * @return HasMany<ThreadSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ThreadSession::class);
    }

    /**
     * @return HasMany<ThreadRestoreStep, $this>
     */
    public function restoreSteps(): HasMany
    {
        return $this->hasMany(ThreadRestoreStep::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<ClaudeAccount, $this>
     */
    public function claudeAccount(): BelongsTo
    {
        return $this->belongsTo(ClaudeAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * The thread's current session: the most recent `main` row.
     */
    public function activeSession(): ?ThreadSession
    {
        return $this->sessions()->where('kind', ThreadSessionKind::Main)->orderByDesc('id')->first();
    }

    /**
     * True while a turn is running in this thread's sandbox or in any thread
     * sharing its container, or while a human holds the sandbox.
     */
    public function isSandboxBusy(): bool
    {
        $sharing = self::query()->withoutGlobalScopes()
            ->when(
                $this->sandbox_container !== null,
                fn (Builder $query) => $query->where('sandbox_container', $this->sandbox_container),
                fn (Builder $query) => $query->whereKey($this->id),
            );

        if ($this->lock_holder !== ThreadLockHolder::None || (clone $sharing)->where('lock_holder', '!=', ThreadLockHolder::None->value)->exists()) {
            return true;
        }

        return TaskRun::query()
            ->where('turn_state', TurnState::Running)
            ->whereIn('thread_id', $sharing->select('id'))
            ->exists();
    }

    public function isRoot(): bool
    {
        return $this->parent_thread_id === null;
    }

    /**
     * The top of this thread's stack; the thread itself when it has no parent.
     */
    public function rootThread(): self
    {
        if ($this->root_thread_id === null) {
            return $this;
        }

        return $this->root ?? $this;
    }

    /**
     * A sub-thread is as visible as its root.
     */
    public function effectiveVisibility(): ThreadVisibility
    {
        return $this->rootThread()->visibility;
    }

    /**
     * A sub-thread belongs to its root's owner.
     */
    public function effectiveOwnerUserId(): ?int
    {
        return $this->rootThread()->owner_user_id;
    }

    public function isPrivate(): bool
    {
        return $this->effectiveVisibility() === ThreadVisibility::Private;
    }

    public function isOpen(): bool
    {
        return $this->status === ThreadStatus::Open;
    }
}
