<?php

namespace App\Drivers;

use App\Contracts\InputDriver;
use App\DataTransferObjects\TaskDescription;
use App\Enums\TaskMode;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LinearInputDriver implements InputDriver
{
    /**
     * Parse a Linear webhook Issue label event into a normalized task description.
     */
    public function parse(Request $request): TaskDescription
    {
        /** @var array{identifier?: string, title?: string, description?: string, url?: string, id?: string} $issue */
        $issue = $request->input('data', []);

        $identifier = $issue['identifier'] ?? '';
        $title = $issue['title'] ?? '';
        $description = $issue['description'] ?? '';
        $issueUrl = $issue['url'] ?? '';
        $issueId = $issue['id'] ?? '';

        $labels = $this->extractLabels($request);
        $mode = $this->detectMode($labels, $title);
        $repository = $this->detectRepo($description);

        $externalId = $identifier !== '' ? "LINEAR-{$identifier}" : $issueId;

        return new TaskDescription(
            title: Str::limit($title, 100),
            body: $description !== '' ? "{$title}\n\n{$description}" : $title,
            channel: 'linear',
            externalId: $externalId,
            repository: $repository,
            metadata: [
                'mode' => $mode->value,
                'title' => $title,
                'description' => $description,
                'linear_issue_id' => $issueId,
                'linear_issue_identifier' => $identifier,
                'linear_issue_url' => $issueUrl,
            ],
        );
    }

    /**
     * Extract label names from the webhook payload.
     *
     * @return list<string>
     */
    private function extractLabels(Request $request): array
    {
        /** @var list<array{name?: string}> $labels */
        $labels = $request->input('data.labels', []);

        return array_map(
            fn (array $label): string => strtolower($label['name'] ?? ''),
            $labels,
        );
    }

    /**
     * Detect task mode from labels or title. A `research` label or the word
     * "research" anywhere in the issue title triggers Research mode.
     *
     * Matching on the title avoids a race where the `yak` label is applied
     * before the `research` label — the webhook fires on `yak` and the
     * `research` label wouldn't be visible yet.
     *
     * @param  list<string>  $labels
     */
    private function detectMode(array $labels, string $title): TaskMode
    {
        if (in_array('research', $labels, true)) {
            return TaskMode::Research;
        }

        if (preg_match('/\bresearch\b/i', $title)) {
            return TaskMode::Research;
        }

        return TaskMode::Fix;
    }

    /**
     * Detect repo from issue description (explicit "repo: owner/name" mention).
     */
    private function detectRepo(string $description): ?string
    {
        if (preg_match('/\brepo:\s*([\w\-\/]+)/i', $description, $matches)) {
            $slug = $matches[1];
            if (Repository::where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        return null;
    }
}
