<?php

namespace App\Drivers;

use App\Contracts\InputDriver;
use App\DataTransferObjects\TaskDescription;
use App\Enums\TaskMode;
use App\Models\Repository;
use App\Services\LinearPromptContextRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LinearInputDriver implements InputDriver
{
    public function __construct(
        private readonly LinearPromptContextRenderer $contextRenderer = new LinearPromptContextRenderer,
    ) {}

    /**
     * Parse a Linear AgentSessionEvent.created payload into a normalized
     * task description. Expects the session + issue blocks to be present
     * under `agentSession`.
     */
    public function parse(Request $request): TaskDescription
    {
        /** @var array{id?: string, issue?: array<string, mixed>, promptContext?: string} $session */
        $session = (array) $request->input('agentSession', []);
        /** @var array{id?: string, identifier?: string, title?: string, description?: string, url?: string} $issue */
        $issue = (array) ($session['issue'] ?? []);

        $identifier = (string) ($issue['identifier'] ?? '');
        $title = (string) ($issue['title'] ?? '');
        $description = (string) ($issue['description'] ?? '');
        $issueUrl = (string) ($issue['url'] ?? '');
        $issueId = (string) ($issue['id'] ?? '');
        $sessionId = (string) ($session['id'] ?? '');
        $promptContextXml = (string) ($session['promptContext'] ?? '');

        $externalId = $identifier !== '' ? "LINEAR-{$identifier}" : $issueId;
        $mode = $this->detectMode($title, $issue);
        $repository = $this->detectRepo($description);
        $body = $this->composeBody($title, $description, $promptContextXml);

        return new TaskDescription(
            title: Str::limit($title, 100),
            body: $body,
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
                'linear_agent_session_id' => $sessionId,
            ],
        );
    }

    /**
     * Research mode is triggered by either (a) the word "research"
     * anywhere in the issue title or (b) a label named "research" on
     * the issue. The label path catches semantically-obvious research
     * tasks where the title happens to read like a fix ("Replace X
     * with Y — evaluate options"), while the title path keeps the
     * low-friction "Research: …" prefix working.
     *
     * @param  array<string, mixed>  $issue  Raw issue payload from the
     *                                       Linear webhook — we inspect
     *                                       `labels` / `labels.nodes`
     *                                       tolerantly since Linear uses
     *                                       both shapes.
     */
    private function detectMode(string $title, array $issue): TaskMode
    {
        if (preg_match('/\bresearch\b/i', $title)) {
            return TaskMode::Research;
        }

        if ($this->hasLabel($issue, 'research')) {
            return TaskMode::Research;
        }

        return TaskMode::Fix;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function hasLabel(array $issue, string $labelName): bool
    {
        $labels = $issue['labels'] ?? null;

        // Normalise to a flat list of label entries. Linear sometimes
        // sends a plain array, sometimes a { nodes: […] } connection
        // wrapper.
        if (is_array($labels) && isset($labels['nodes']) && is_array($labels['nodes'])) {
            $labels = $labels['nodes'];
        }

        if (! is_array($labels)) {
            return false;
        }

        foreach ($labels as $label) {
            $name = is_array($label) ? ($label['name'] ?? '') : $label;
            if (is_string($name) && strcasecmp($name, $labelName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect repo from an explicit `repo: owner/name` mention in the
     * issue description.
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

    /**
     * Compose the task body from title + description, appending the
     * markdown-rendered promptContext when Linear supplied one. Falls
     * back gracefully when promptContext is empty or unparseable.
     */
    private function composeBody(string $title, string $description, string $promptContextXml): string
    {
        $core = $description !== '' ? "{$title}\n\n{$description}" : $title;

        $contextMarkdown = $this->contextRenderer->render($promptContextXml);
        if ($contextMarkdown === '') {
            return $core;
        }

        return "{$core}\n\n---\n\n{$contextMarkdown}";
    }
}
