<?php

namespace App\Services\Threads;

use App\Enums\TaskMode;
use App\Enums\ThreadSource;
use App\Models\Thread;
use App\Models\YakTask;
use App\Support\TaskSourceUrl;
use Illuminate\Support\Str;

/**
 * Works out where a thread came from and who started it, from the request
 * that created it, so thread lists can filter on those facts in SQL.
 */
class ThreadOrigin
{
    /**
     * @return array{source: ThreadSource, source_reference: ?string, source_url: ?string, starter_name: ?string}
     */
    public function forRequest(?YakTask $request, ThreadSource $fallback = ThreadSource::Dashboard): array
    {
        if ($request === null) {
            return ['source' => $fallback, 'source_reference' => null, 'source_url' => null, 'starter_name' => null];
        }

        $source = $this->isRiskProfileResearch($request)
            ? ThreadSource::RepositoryResearch
            : $this->sourceOf((string) $request->source, $fallback);
        $authorName = trim((string) $request->author_name);

        return [
            'source' => $source,
            'source_reference' => $this->reference($request),
            'source_url' => TaskSourceUrl::resolve($request),
            'starter_name' => $request->started_by_user_id !== null || $authorName === '' ? null : $authorName,
        ];
    }

    /**
     * The key a thread list filters starters by: the user, else the named
     * outside author, else Yak itself.
     */
    public function starterKey(Thread $thread): string
    {
        return match (true) {
            $thread->created_by_user_id !== null => 'user:' . $thread->created_by_user_id,
            $thread->starter_name !== null => 'name:' . $thread->starter_name,
            default => 'yak',
        };
    }

    private function sourceOf(string $taskSource, ThreadSource $fallback): ThreadSource
    {
        return match ($taskSource) {
            'flaky-test' => ThreadSource::Github,
            'manual', 'system' => ThreadSource::Cli,
            default => ThreadSource::tryFrom(str_replace('-', '_', $taskSource)) ?? $fallback,
        };
    }

    public function isRiskProfileResearch(YakTask $request): bool
    {
        return $request->mode === TaskMode::Research
            && str_starts_with((string) $request->external_id, 'risk-profile-');
    }

    private function reference(YakTask $request): ?string
    {
        $externalId = (string) $request->external_id;

        return match ((string) $request->source) {
            'linear' => $externalId !== '' ? Str::after($externalId, 'LINEAR-') : null,
            'sentry' => $this->sentryReference($request, $externalId),
            default => null,
        };
    }

    private function sentryReference(YakTask $request, string $externalId): ?string
    {
        $context = json_decode((string) $request->context, true);
        $issueId = is_array($context) ? (string) ($context['sentry_issue_id'] ?? '') : '';

        return $issueId !== '' ? $issueId : ($externalId !== '' ? $externalId : null);
    }
}
