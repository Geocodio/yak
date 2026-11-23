<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The settings an admin may change on the Limits page, and the overrides saved for them.
 *
 * A saved value replaces the config value for its key. A missing row leaves the config (env) value alone.
 * Bind this as a singleton: it remembers each key's original config value so the default can still be shown.
 *
 * @phpstan-type SettingDefinition array{id: string, key: string, group: string, label: string, help: ?string, unit: ?string, type: 'int'|'decimal'|'bool', min: int|float|null, max: int|float|null, suffix?: string}
 * @phpstan-type SettingGroup array{title: string, description: ?string}
 */
class EditableSettings
{
    public const CACHE_KEY = 'yak:setting-overrides';

    /** @var list<string> */
    public const GROUP_ORDER = ['sandboxes', 'sub-threads', 'previews', 'spend', 'reviews', 'retention'];

    /** @var array<string, SettingGroup> */
    public const GROUPS = [
        'sandboxes' => ['title' => 'Sandboxes', 'description' => 'Every awake sandbox holds memory on this server, even when it is paused.'],
        'sub-threads' => ['title' => 'Sub-threads', 'description' => null],
        'previews' => ['title' => 'Previews', 'description' => null],
        'spend' => ['title' => 'Tasks and spend', 'description' => null],
        'reviews' => ['title' => 'PR reviews', 'description' => null],
        'retention' => ['title' => 'Keeping data', 'description' => null],
    ];

    /** @var array<string, mixed> */
    private array $originals = [];

    private bool $hasCapturedOriginals = false;

    /** @var array<string, mixed> */
    private array $appliedKeys = [];

    /**
     * @return list<SettingDefinition>
     */
    public function definitions(): array
    {
        return [
            ['id' => 'awake_cap', 'key' => 'yak.threads.awake_cap', 'group' => 'sandboxes', 'label' => 'Awake sandboxes on this server', 'help' => 'Running and paused sandboxes together. More than this and the oldest idle one stops.', 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 500],
            ['id' => 'person_awake_cap', 'key' => 'yak.threads.person_awake_cap', 'group' => 'sandboxes', 'label' => 'Awake sandboxes per person', 'help' => "Counts a person's threads and their sub-threads. Stops one person from using every slot.", 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 500],
            ['id' => 'memory_floor_gb', 'key' => 'yak.threads.memory_floor_gb', 'group' => 'sandboxes', 'label' => 'Memory to keep free', 'help' => 'Below this, Yak stops an idle sandbox before it starts another.', 'unit' => 'GB', 'type' => 'int', 'min' => 1, 'max' => 4096],
            ['id' => 'sandbox_memory_gb', 'key' => 'yak.sandbox.memory_limit', 'group' => 'sandboxes', 'label' => 'Memory per sandbox', 'help' => 'The most memory one sandbox may use. New sandboxes only.', 'unit' => 'GB', 'type' => 'int', 'min' => 1, 'max' => 4096, 'suffix' => 'GB'],
            ['id' => 'sandbox_cpu', 'key' => 'yak.sandbox.cpu_limit', 'group' => 'sandboxes', 'label' => 'CPU per sandbox', 'help' => 'New sandboxes only.', 'unit' => 'cores', 'type' => 'int', 'min' => 1, 'max' => 256],
            ['id' => 'pause_to_stop_minutes', 'key' => 'yak.threads.pause_to_stop_minutes', 'group' => 'sandboxes', 'label' => 'Stop a paused sandbox after', 'help' => 'The thread keeps its history. The next message rebuilds the sandbox.', 'unit' => 'min', 'type' => 'int', 'min' => 1, 'max' => 10080],
            ['id' => 'stop_to_destroy_hours', 'key' => 'yak.threads.stop_to_destroy_hours', 'group' => 'sandboxes', 'label' => 'Remove a stopped sandbox after', 'help' => 'Frees its disk. Uncommitted files in it are lost.', 'unit' => 'hours', 'type' => 'int', 'min' => 1, 'max' => 8760],
            ['id' => 'max_children', 'key' => 'yak.threads.max_children', 'group' => 'sub-threads', 'label' => 'Sub-threads per thread', 'help' => null, 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 100],
            ['id' => 'child_awake_limit', 'key' => 'yak.threads.child_awake_limit', 'group' => 'sub-threads', 'label' => 'Awake sub-threads per thread', 'help' => 'People can lower this per thread. It can never be higher than the per person limit.', 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 500],
            ['id' => 'running_cap', 'key' => 'yak.deployments.running_cap', 'group' => 'previews', 'label' => 'Running previews', 'help' => 'More than this and the preview idle longest goes to sleep.', 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 200],
            ['id' => 'preview_idle_minutes', 'key' => 'yak.deployments.idle_minutes', 'group' => 'previews', 'label' => 'Put a preview to sleep after', 'help' => null, 'unit' => 'min', 'type' => 'int', 'min' => 1, 'max' => 10080],
            ['id' => 'preview_destroy_days', 'key' => 'yak.deployments.destroy_days', 'group' => 'previews', 'label' => 'Remove a preview after', 'help' => null, 'unit' => 'days', 'type' => 'int', 'min' => 1, 'max' => 365],
            ['id' => 'daily_budget_usd', 'key' => 'yak.daily_budget_usd', 'group' => 'spend', 'label' => 'Daily budget', 'help' => 'Yak stops starting tasks for the day when it reaches this.', 'unit' => 'USD', 'type' => 'decimal', 'min' => 1, 'max' => 100000],
            ['id' => 'max_budget_per_task', 'key' => 'yak.max_budget_per_task', 'group' => 'spend', 'label' => 'Budget per task', 'help' => null, 'unit' => 'USD', 'type' => 'decimal', 'min' => 0.5, 'max' => 10000],
            ['id' => 'max_attempts', 'key' => 'yak.max_attempts', 'group' => 'spend', 'label' => 'Attempts per task', 'help' => null, 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 20],
            ['id' => 'max_turns', 'key' => 'yak.max_turns', 'group' => 'spend', 'label' => 'Turns per task', 'help' => null, 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 5000],
            ['id' => 'pr_review_enabled', 'key' => 'yak.pr_review.enabled_globally', 'group' => 'reviews', 'label' => 'Review pull requests', 'help' => 'Repositories can still turn it off one at a time.', 'unit' => null, 'type' => 'bool', 'min' => null, 'max' => null],
            ['id' => 'pr_review_self_enabled', 'key' => 'yak.pr_review.self_review_enabled', 'group' => 'reviews', 'label' => "Review Yak's own pull requests", 'help' => null, 'unit' => null, 'type' => 'bool', 'min' => null, 'max' => null],
            ['id' => 'max_findings', 'key' => 'yak.pr_review.max_findings_per_review', 'group' => 'reviews', 'label' => 'Findings per review', 'help' => null, 'unit' => null, 'type' => 'int', 'min' => 1, 'max' => 100],
            ['id' => 'session_retention_days', 'key' => 'yak.threads.session_retention_days', 'group' => 'retention', 'label' => 'Thread sessions', 'help' => null, 'unit' => 'days', 'type' => 'int', 'min' => 1, 'max' => 3650],
            ['id' => 'video_retention_days', 'key' => 'yak.video.raw_retention_days', 'group' => 'retention', 'label' => 'Raw walkthrough video', 'help' => null, 'unit' => 'days', 'type' => 'int', 'min' => 1, 'max' => 3650],
            ['id' => 'telemetry_retention_days', 'key' => 'yak.telemetry.retention_days', 'group' => 'retention', 'label' => 'Telemetry', 'help' => null, 'unit' => 'days', 'type' => 'int', 'min' => 1, 'max' => 3650],
        ];
    }

    /**
     * @return SettingDefinition|null
     */
    public function definitionForKey(string $key): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Copies the saved overrides into config. Safe to call again: a key whose override is gone returns to its
     * original value, and the saved rows come from the cache, so a call costs no query once the cache is warm.
     */
    public function apply(): void
    {
        $this->captureOriginals();

        $overrides = $this->overrides();

        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];

            if (array_key_exists($key, $overrides)) {
                config([$key => $this->toConfig($definition, $overrides[$key])]);
            } elseif (array_key_exists($key, $this->appliedKeys)) {
                config([$key => $this->originals[$key]]);
            }
        }

        $this->appliedKeys = array_intersect_key($overrides, array_flip(array_column($this->definitions(), 'key')));
    }

    /**
     * The saved overrides by config key; empty while the table does not exist yet.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        try {
            /** @var array<string, mixed> $overrides */
            $overrides = Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());

            return $overrides;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Saves one override, or removes it when the value equals the default.
     *
     * @param  SettingDefinition  $definition
     */
    public function store(array $definition, int|float|bool $value): void
    {
        if ($this->normalize($definition, $value) === $this->normalize($definition, $this->defaultValue($definition))) {
            Setting::query()->where('key', $definition['key'])->delete();

            return;
        }

        Setting::query()->updateOrCreate(['key' => $definition['key']], ['value' => $this->normalize($definition, $value)]);
    }

    public function resetAll(): void
    {
        Setting::query()->delete();
        $this->refresh();
    }

    /** Drops the cached rows and reapplies them, after a save. */
    public function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->apply();
    }

    /**
     * The value config holds with no override, in the unit the page shows.
     *
     * @param  SettingDefinition  $definition
     */
    public function defaultValue(array $definition): int|float|bool
    {
        $this->captureOriginals();

        return $this->fromConfig($definition, $this->originals[$definition['key']] ?? config($definition['key']));
    }

    /**
     * The value in effect now, in the unit the page shows.
     *
     * @param  SettingDefinition  $definition
     */
    public function currentValue(array $definition): int|float|bool
    {
        return $this->fromConfig($definition, config($definition['key']));
    }

    /**
     * @param  SettingDefinition  $definition
     */
    public function isOverridden(array $definition): bool
    {
        return array_key_exists($definition['key'], $this->overrides());
    }

    private function captureOriginals(): void
    {
        if ($this->hasCapturedOriginals) {
            return;
        }

        foreach ($this->definitions() as $definition) {
            $this->originals[$definition['key']] = config($definition['key']);
        }

        $this->hasCapturedOriginals = true;
    }

    /**
     * @param  SettingDefinition  $definition
     */
    private function toConfig(array $definition, mixed $value): mixed
    {
        return match ($definition['type']) {
            'bool' => (bool) $value,
            'decimal' => (float) $value,
            default => isset($definition['suffix']) ? (int) $value . $definition['suffix'] : (int) $value,
        };
    }

    /**
     * @param  SettingDefinition  $definition
     */
    private function fromConfig(array $definition, mixed $value): int|float|bool
    {
        return match ($definition['type']) {
            'bool' => (bool) $value,
            'decimal' => (float) $value,
            default => (int) $value,
        };
    }

    /**
     * @param  SettingDefinition  $definition
     */
    private function normalize(array $definition, int|float|bool $value): int|float|bool
    {
        return match ($definition['type']) {
            'bool' => (bool) $value,
            'decimal' => round((float) $value, 2),
            default => (int) $value,
        };
    }
}
