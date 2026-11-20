<?php

namespace App\Models;

use App\Enums\ThreadAgentKind;
use App\Enums\ThreadAgentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ThreadAgentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One agent or workflow Claude started inside a thread. `task_id` is Claude's own id, the id the
 * runner stops it by; `task_run_id` is the turn that started it and `follow_up_run_id` the turn
 * Claude took after it finished.
 *
 * @property int $thread_id
 * @property int|null $task_run_id
 * @property int|null $follow_up_run_id
 * @property string $task_id
 * @property string|null $parent_task_id
 * @property string|null $tool_use_id
 * @property int $depth
 * @property ThreadAgentKind $kind
 * @property string|null $agent_type
 * @property string|null $description
 * @property string|null $workflow_name
 * @property string|null $model
 * @property bool $is_background
 * @property bool $needs_follow_up
 * @property ThreadAgentStatus $status
 * @property string|null $last_tool
 * @property string|null $summary
 * @property int $total_tokens
 * @property int $tool_uses
 * @property int $duration_ms
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $stop_requested_at
 * @property string|null $stop_error
 * @property list<array<string, mixed>>|null $workflow_progress
 */
class ThreadAgent extends Model
{
    /** @use HasFactory<ThreadAgentFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ThreadAgentKind::class,
            'status' => ThreadAgentStatus::class,
            'is_background' => 'boolean',
            'needs_follow_up' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'stop_requested_at' => 'immutable_datetime',
            'workflow_progress' => 'array',
        ];
    }

    /**
     * @param  Builder<ThreadAgent>  $query
     * @return Builder<ThreadAgent>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', ThreadAgentStatus::Running->value);
    }

    /**
     * Agents are tracked only while the subagents flag is on; with it off nothing here may keep a thread busy.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('yak.threads.subagents');
    }

    public static function hasRunning(int $threadId): bool
    {
        return self::isEnabled() && self::query()->where('thread_id', $threadId)->running()->exists();
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return HasMany<ThreadAgentMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ThreadAgentMessage::class)->orderBy('id');
    }

    public function isRunning(): bool
    {
        return $this->status === ThreadAgentStatus::Running;
    }

    /**
     * The label Claude's steering message names the agent by.
     */
    public function label(): string
    {
        return trim((string) ($this->description ?? $this->workflow_name ?? $this->agent_type ?? 'agent'));
    }
}
