<?php

namespace App\Channels\Sentry;

use App\Channels\Contracts\InputDriver as InputDriverContract;
use App\DataTransferObjects\TaskDescription;
use App\Enums\TaskMode;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InputDriver implements InputDriverContract
{
    /**
     * Parse a Sentry issue alert (`event_alert`) webhook into a normalized task description.
     *
     * The payload carries a single event under `data.event`; issue-level
     * aggregates such as event count are not part of it.
     */
    public function parse(Request $request): TaskDescription
    {
        /** @var array{issue_id?: string|int, title?: string, culprit?: string, url?: string, web_url?: string} $event */
        $event = $request->input('data.event', []);

        $issueId = (string) ($event['issue_id'] ?? '');
        $title = (string) ($event['title'] ?? '');
        $culprit = (string) ($event['culprit'] ?? '');
        $webUrl = (string) ($event['web_url'] ?? '');
        $projectSlug = self::projectSlug($request);

        $stacktrace = $this->formatStacktrace($this->extractStacktrace($request));

        $repository = Repository::where('sentry_project', $projectSlug)
            ->where('is_active', true)
            ->first();

        return new TaskDescription(
            title: Str::limit($title, 100),
            body: $this->buildBody($title, $culprit, $webUrl, $stacktrace),
            channel: 'sentry',
            externalId: $issueId,
            repository: $repository?->slug,
            metadata: [
                'mode' => TaskMode::Fix->value,
                'sentry_issue_id' => $issueId,
                'sentry_project' => $projectSlug,
                'error' => $title,
                'culprit' => $culprit,
                'stacktrace' => $stacktrace,
                'context' => $webUrl !== '' ? "Sentry event: {$webUrl}" : '',
            ],
        );
    }

    /**
     * Read the project slug from the event's API URL.
     *
     * `data.event.project` is the numeric project ID; the slug only appears in
     * `data.event.url` as `/api/0/projects/{org}/{project}/events/{id}/`.
     */
    public static function projectSlug(Request $request): string
    {
        $url = (string) $request->input('data.event.url', '');

        if (preg_match('#/projects/[^/]+/([^/]+)/events/#', $url, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Extract the last 10 stacktrace frames from the event's exception.
     *
     * @return list<array{filename?: string|null, function?: string|null, lineno?: int|null}>
     */
    private function extractStacktrace(Request $request): array
    {
        /** @var list<array{stacktrace?: array{frames?: list<array{filename?: string|null, function?: string|null, lineno?: int|null}>}}> $values */
        $values = $request->input('data.event.exception.values', []);

        foreach ($values as $value) {
            $frames = $value['stacktrace']['frames'] ?? [];

            if ($frames !== []) {
                // Most recent frame is last in Sentry's ordering
                return array_slice($frames, -10);
            }
        }

        return [];
    }

    /**
     * @param  list<array{filename?: string|null, function?: string|null, lineno?: int|null}>  $frames
     */
    private function formatStacktrace(array $frames): string
    {
        return implode("\n", array_map(function (array $frame): string {
            $file = $frame['filename'] ?? '?';
            $function = $frame['function'] ?? '?';
            $line = $frame['lineno'] ?? '?';

            return "  {$file}:{$line} in {$function}";
        }, $frames));
    }

    private function buildBody(string $title, string $culprit, string $webUrl, string $stacktrace): string
    {
        $lines = [
            "**Sentry Issue:** {$title}",
            "**Culprit:** {$culprit}",
        ];

        if ($webUrl !== '') {
            $lines[] = "**Event:** {$webUrl}";
        }

        if ($stacktrace !== '') {
            $lines[] = '';
            $lines[] = '**Stacktrace (top frames):**';
            $lines[] = $stacktrace;
        }

        return implode("\n", $lines);
    }
}
