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
     * Parse a Sentry webhook issue alert event into a normalized task description.
     */
    public function parse(Request $request): TaskDescription
    {
        /** @var array{id?: string|int, title?: string, culprit?: string, count?: string|int, firstSeen?: string, userCount?: int, project?: array{slug?: string}} $issue */
        $issue = $request->input('data.issue', []);

        $issueId = (string) ($issue['id'] ?? '');
        $title = (string) ($issue['title'] ?? '');
        $culprit = (string) ($issue['culprit'] ?? '');
        $eventCount = (int) ($issue['count'] ?? 0);
        $firstSeen = (string) ($issue['firstSeen'] ?? '');
        $userCount = (int) ($issue['userCount'] ?? 0);
        $projectSlug = (string) ($issue['project']['slug'] ?? '');

        $stacktrace = $this->extractStacktrace($request);

        $repository = Repository::where('sentry_project', $projectSlug)
            ->where('is_active', true)
            ->first();

        $body = $this->buildBody($title, $culprit, $stacktrace, $eventCount, $firstSeen, $userCount);

        return new TaskDescription(
            title: Str::limit($title, 100),
            body: $body,
            channel: 'sentry',
            externalId: $issueId,
            repository: $repository?->slug,
            metadata: [
                'mode' => TaskMode::Fix->value,
                'sentry_issue_id' => $issueId,
                'sentry_project' => $projectSlug,
                'event_count' => $eventCount,
                'first_seen' => $firstSeen,
                'affected_users' => $userCount,
                'culprit' => $culprit,
            ],
        );
    }

    /**
     * Extract the top 10 stacktrace frames from the event data.
     *
     * @return list<array{filename?: string, function?: string, lineno?: int}>
     */
    private function extractStacktrace(Request $request): array
    {
        /** @var list<array{type?: string, data?: array{values?: list<array{stacktrace?: array{frames?: list<array{filename?: string, function?: string, lineno?: int}>}}>}}> $entries */
        $entries = $request->input('data.event.entries', []);

        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') !== 'exception') {
                continue;
            }

            /** @var list<array{stacktrace?: array{frames?: list<array{filename?: string, function?: string, lineno?: int}>}}> $values */
            $values = $entry['data']['values'] ?? [];

            foreach ($values as $value) {
                $frames = $value['stacktrace']['frames'] ?? [];

                if ($frames !== []) {
                    // Return last 10 frames (most recent at end in Sentry format)
                    return array_slice($frames, -10);
                }
            }
        }

        return [];
    }

    /**
     * Build a human-readable task body with all relevant context.
     *
     * @param  list<array{filename?: string, function?: string, lineno?: int}>  $stacktrace
     */
    private function buildBody(
        string $title,
        string $culprit,
        array $stacktrace,
        int $eventCount,
        string $firstSeen,
        int $userCount,
    ): string {
        $lines = [
            "**Sentry Issue:** {$title}",
            "**Culprit:** {$culprit}",
            "**Event Count:** {$eventCount}",
            "**First Seen:** {$firstSeen}",
            "**Affected Users:** {$userCount}",
        ];

        if ($stacktrace !== []) {
            $lines[] = '';
            $lines[] = '**Stacktrace (top frames):**';

            foreach ($stacktrace as $frame) {
                $file = $frame['filename'] ?? '?';
                $func = $frame['function'] ?? '?';
                $line = $frame['lineno'] ?? '?';
                $lines[] = "  {$file}:{$line} in {$func}";
            }
        }

        return implode("\n", $lines);
    }
}
