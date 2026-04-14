<?php

namespace App\Services;

use App\Models\LinearOauthConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinearIssueFetcher
{
    private const GRAPHQL_URL = 'https://api.linear.app/graphql';

    private const QUERY = <<<'GQL'
query IssueContext($id: String!) {
    issue(id: $id) {
        id
        identifier
        title
        description
        priority
        priorityLabel
        url
        state { name }
        assignee { displayName }
        creator { displayName }
        project { name url }
        parent { identifier title }
        children(first: 50) {
            nodes { identifier title }
        }
        comments(first: 50, orderBy: createdAt) {
            nodes {
                body
                createdAt
                user { displayName }
                botActor { name }
                parent { id }
            }
        }
        attachments(first: 50) {
            nodes {
                title
                url
                sourceType
            }
        }
    }
}
GQL;

    public function __construct(private readonly LinearOAuthService $oauth) {}

    /**
     * Fetch enriched context for a Linear issue. Returns null if no
     * OAuth connection is available, the issue can't be found, or any
     * unexpected error occurs — this is a best-effort enrichment, the
     * caller should still proceed without it.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(string $issueId): ?array
    {
        $connection = LinearOauthConnection::active();
        if ($connection === null) {
            return null;
        }

        try {
            $accessToken = $connection->freshAccessToken($this->oauth);
        } catch (\Throwable $e) {
            Log::warning('LinearIssueFetcher: could not refresh OAuth token', [
                'issue_id' => $issueId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->post(self::GRAPHQL_URL, [
                'query' => self::QUERY,
                'variables' => ['id' => $issueId],
            ]);

        if (! $response->successful()) {
            Log::warning('LinearIssueFetcher: GraphQL request failed', [
                'issue_id' => $issueId,
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 200),
            ]);

            return null;
        }

        /** @var array{data?: array{issue?: array<string, mixed>|null}, errors?: list<array{message?: string}>} $json */
        $json = $response->json();

        if (! empty($json['errors'])) {
            Log::warning('LinearIssueFetcher: GraphQL errors', [
                'issue_id' => $issueId,
                'errors' => $json['errors'],
            ]);

            return null;
        }

        return $json['data']['issue'] ?? null;
    }

    /**
     * Render the fetched issue as a markdown block suitable for inlining
     * into the task prompt. Empty sections are skipped.
     *
     * @param  array<string, mixed>  $issue
     */
    public function renderAsMarkdown(array $issue): string
    {
        $sections = [];

        $sections[] = $this->renderHeader($issue);

        if ($description = (string) ($issue['description'] ?? '')) {
            $sections[] = "## Description\n\n" . trim($description);
        }

        if ($parent = $issue['parent'] ?? null) {
            $sections[] = "## Parent issue\n\n- {$parent['identifier']}: {$parent['title']}";
        }

        if ($children = $issue['children']['nodes'] ?? []) {
            $lines = array_map(
                fn (array $c): string => "- {$c['identifier']}: {$c['title']}",
                $children,
            );
            $sections[] = "## Sub-issues\n\n" . implode("\n", $lines);
        }

        if ($attachments = $issue['attachments']['nodes'] ?? []) {
            $lines = array_map(
                function (array $a): string {
                    $type = $a['sourceType'] ? " *({$a['sourceType']})*" : '';

                    return "- [{$a['title']}]({$a['url']}){$type}";
                },
                $attachments,
            );
            $sections[] = "## Linked attachments\n\n" . implode("\n", $lines);
        }

        if ($comments = $issue['comments']['nodes'] ?? []) {
            $rendered = $this->renderComments($comments);
            if ($rendered !== '') {
                $sections[] = "## Comments ({$this->renderedCommentCount($comments)})\n\n" . $rendered;
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function renderHeader(array $issue): string
    {
        $parts = [];

        if ($state = $issue['state']['name'] ?? null) {
            $parts[] = "**State:** {$state}";
        }
        if ($priority = $issue['priorityLabel'] ?? null) {
            $parts[] = "**Priority:** {$priority}";
        }
        if ($assignee = $issue['assignee']['displayName'] ?? null) {
            $parts[] = "**Assignee:** {$assignee}";
        }
        if ($creator = $issue['creator']['displayName'] ?? null) {
            $parts[] = "**Created by:** {$creator}";
        }
        if ($project = $issue['project']['name'] ?? null) {
            $parts[] = "**Project:** {$project}";
        }

        return empty($parts) ? '' : implode(' · ', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $comments
     */
    private function renderedCommentCount(array $comments): int
    {
        return count(array_filter(
            $comments,
            fn (array $c): bool => trim((string) ($c['body'] ?? '')) !== '',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $comments
     */
    private function renderComments(array $comments): string
    {
        $blocks = [];

        foreach ($comments as $comment) {
            $author = $comment['user']['displayName']
                ?? $comment['botActor']['name']
                ?? 'Unknown';
            $when = $comment['createdAt'] ?? '';
            $body = trim((string) ($comment['body'] ?? ''));

            if ($body === '') {
                continue;
            }

            $isReply = ! empty($comment['parent']['id']);
            $prefix = $isReply ? '↳ ' : '';

            $blocks[] = "**{$prefix}{$author}** _({$when})_:\n\n{$body}";
        }

        return implode("\n\n---\n\n", $blocks);
    }
}
