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
        /** @var array{agentSession?: array{id?: string, issue?: array<string, mixed>, promptContext?: string}} $payload */
        $payload = (array) json_decode($request->getContent(), associative: true);
        /** @var array{id?: string, issue?: array<string, mixed>, promptContext?: string} $session */
        $session = (array) ($payload['agentSession'] ?? []);
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
        $mode = $this->detectMode($title);
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
     * Research mode is triggered by the word "research" anywhere in the
     * issue title — the most reliable hint available at session creation
     * time.
     */
    private function detectMode(string $title): TaskMode
    {
        if (preg_match('/\bresearch\b/i', $title)) {
            return TaskMode::Research;
        }

        return TaskMode::Fix;
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
