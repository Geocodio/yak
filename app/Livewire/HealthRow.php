<?php

namespace App\Livewire;

use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\Registry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class HealthRow extends Component
{
    public string $checkId;

    private const CACHE_TTL_SECONDS = 60;

    /**
     * The probe runs every 15 minutes; anything older than roughly two
     * intervals means the scheduler likely died, not that the result is
     * merely a little behind. Past this age the stored result is no longer
     * trustworthy enough to show as a plain "Authenticated" green.
     */
    private const STALE_PROBE_MINUTES = 35;

    /**
     * Maps a check ID to the most relevant docs anchor. Used to render a
     * "?" icon link next to each row so users can jump straight to the
     * section explaining how to configure or fix that dependency.
     *
     * @var array<string, string>
     */
    private const DOCS_ANCHOR_BY_CHECK = [
        'queue-worker' => 'troubleshooting',
        'last-task-completed' => 'troubleshooting',
        'incus-daemon' => 'architecture.sandbox',
        'sandbox-base-template' => 'architecture.sandbox',
        'claude-cli' => 'troubleshooting.cli',
        'claude-auth' => 'troubleshooting.cli',
        'repositories' => 'repositories',
        'webhook-signatures' => 'troubleshooting.webhooks',
        'slack' => 'channels.slack',
        'linear' => 'channels.linear',
        'sentry' => 'channels.sentry',
        'github' => 'channels.github',
        'drone' => 'channels.drone',
    ];

    public function placeholder(): string
    {
        $name = app(Registry::class)->nameFor($this->checkId);

        return view('livewire.partials.health-row-skeleton', ['name' => $name])->render();
    }

    public function docsAnchor(): ?string
    {
        return self::DOCS_ANCHOR_BY_CHECK[$this->checkId] ?? null;
    }

    #[Computed]
    public function result(): HealthResult
    {
        // claude-auth's probe is a real (up to 120s) inference call. Running
        // it inline here would execute inside a web request: nginx's default
        // fastcgi_read_timeout (60s, unset in docker/nginx.conf) would kill
        // PHP-FPM mid-probe and orphan the shared .oauth_refresh.lock for
        // every sandbox. Only the scheduled `yak:healthcheck` command may
        // invoke ClaudeAuthCheck::run(); this renders what it last published.
        if ($this->checkId === 'claude-auth') {
            return $this->claudeAuthResult();
        }

        return Cache::remember(
            "health:check:{$this->checkId}",
            self::CACHE_TTL_SECONDS,
            fn () => app(Registry::class)->get($this->checkId)->run(),
        );
    }

    private function claudeAuthResult(): HealthResult
    {
        /** @var array{result: HealthResult, checked_at: Carbon}|null $stored */
        $stored = Cache::get(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY);

        if ($stored === null) {
            return HealthResult::warn('Not yet probed — waiting for the next scheduled health check (runs every 15 minutes)');
        }

        $result = $stored['result'];
        $checkedAt = Carbon::parse($stored['checked_at']);
        $age = $checkedAt->diffForHumans();

        if ($checkedAt->diffInMinutes(now()) > self::STALE_PROBE_MINUTES) {
            $staleDetail = "Stale probe result from {$age} — the scheduler may not be running. Last known state: {$result->detail}";

            // Staleness only ever degrades an Ok result to a Warn. It must
            // never upgrade-by-erasure a worse status (Error/NotConnected):
            // downgrading a genuine Error to Warn would silently drop the
            // re-authentication HealthAction (warn() takes no action), and
            // would show a stale red probe as merely yellow.
            if ($result->status === HealthStatus::Ok) {
                return HealthResult::warn($staleDetail);
            }

            return new HealthResult($result->status, $staleDetail, $result->action);
        }

        return new HealthResult($result->status, "{$result->detail} (checked {$age})", $result->action);
    }

    #[Computed]
    public function name(): string
    {
        return app(Registry::class)->nameFor($this->checkId);
    }

    public function refresh(): void
    {
        Cache::forget("health:check:{$this->checkId}");
        unset($this->result);
    }

    #[On('health-refresh')]
    public function handleRefresh(): void
    {
        $this->refresh();
    }
}
