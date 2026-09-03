<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\HealthResultData;
use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\Registry;
use App\Support\Docs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class HealthController extends Controller
{
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

    public function index(): Response
    {
        $registry = app(Registry::class);

        $systemIds = array_map(fn ($check): string => $check->id(), $registry->forSection(HealthSection::System));
        $channelIds = array_map(fn ($check): string => $check->id(), $registry->forSection(HealthSection::Channels));

        return Inertia::render('Health/Index', [
            'systemChecks' => array_map(fn (string $id) => $this->checkMeta($registry, $id), $systemIds),
            'channelChecks' => array_map(fn (string $id) => $this->checkMeta($registry, $id), $channelIds),
            'systemResults' => Inertia::defer(fn () => $this->allResults($systemIds), 'system'),
            'channelResults' => Inertia::defer(fn () => $this->allResults($channelIds), 'channels'),
        ]);
    }

    public function refreshAll(): RedirectResponse
    {
        $registry = app(Registry::class);

        foreach ($registry->ids() as $id) {
            Cache::forget("health:check:{$id}");
        }

        return back();
    }

    public function refreshOne(string $check): RedirectResponse
    {
        abort_unless(in_array($check, app(Registry::class)->ids(), true), 404);

        Cache::forget("health:check:{$check}");

        return back();
    }

    /**
     * @return array{id: string, name: string, docsUrl: ?string}
     */
    private function checkMeta(Registry $registry, string $id): array
    {
        $anchor = self::DOCS_ANCHOR_BY_CHECK[$id] ?? null;

        return [
            'id' => $id,
            'name' => $registry->nameFor($id),
            'docsUrl' => $anchor !== null ? Docs::url($anchor) : null,
        ];
    }

    /**
     * Resolves every check's result, keyed by id. Each individual result is
     * still cached (60s, per check id -- see resultFor()), so a partial
     * reload that re-requests this whole map after a single row's cache
     * entry was cleared only re-runs that one check; the rest are served
     * from cache.
     *
     * Note: this is one deferred prop per section, not one deferred prop
     * per check id. Inertia's partial-reload resolver treats every child of
     * an already-resolved array as included once its parent path matches the
     * request (see PropsResolver::shouldIncludeInPartialResponse, where
     * `parentWasResolved` short-circuits the per-child "only" filter), so
     * nesting `Inertia::defer()` calls under a shared results array does not
     * give independent per-id partial loads -- requesting `results.{id}`
     * still resolves every id.
     *
     * Sections are separate top-level deferred props in separate groups,
     * which Inertia does fetch independently. System checks are fast and
     * local; channel checks hit third-party APIs and dominate the page's
     * load time. Splitting them means the System section paints as soon as
     * it is ready instead of waiting on the slowest channel.
     *
     * @param  list<string>  $ids
     * @return array<string, HealthResultData>
     */
    private function allResults(array $ids): array
    {
        $results = [];

        foreach ($ids as $id) {
            $results[$id] = $this->resultFor($id);
        }

        return $results;
    }

    private function resultFor(string $checkId): HealthResultData
    {
        if ($checkId === 'claude-auth') {
            return $this->claudeAuthResultData();
        }

        $result = Cache::remember(
            "health:check:{$checkId}",
            self::CACHE_TTL_SECONDS,
            fn () => app(Registry::class)->get($checkId)->run(),
        );

        return HealthResultData::from($result);
    }

    private function claudeAuthResultData(): HealthResultData
    {
        /** @var array{result: HealthResult, checked_at: string}|null $stored */
        $stored = Cache::get(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY);

        if ($stored === null) {
            return HealthResultData::from(
                HealthResult::warn('Not yet probed — waiting for the next scheduled health check (runs every 15 minutes)'),
            );
        }

        $result = $stored['result'];
        $checkedAt = Carbon::parse($stored['checked_at']);
        $age = $checkedAt->diffForHumans();

        if ($checkedAt->diffInMinutes(now()) > self::STALE_PROBE_MINUTES) {
            $staleDetail = "Stale probe result from {$age} — the scheduler may not be running. Last known state: {$result->detail}";

            // Staleness only ever degrades an Ok result to a Warn. It must
            // never upgrade-by-erasure a worse status (Error/NotConnected):
            // downgrading a genuine Error to Warn would silently drop the
            // re-authentication action, and would show a stale red probe
            // as merely yellow.
            if ($result->status === HealthStatus::Ok) {
                return HealthResultData::from(HealthResult::warn($staleDetail));
            }

            return HealthResultData::from(new HealthResult($result->status, $staleDetail, $result->action));
        }

        return HealthResultData::from(
            new HealthResult($result->status, "{$result->detail} ({$age})", $result->action),
            checkedAgo: $age,
        );
    }
}
