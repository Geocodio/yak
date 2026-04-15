<?php

namespace App\Services;

use SimpleXMLElement;
use Throwable;

class LinearPromptContextRenderer
{
    /**
     * Convert Linear's `promptContext` XML blob into a markdown block the
     * agent can consume as part of its task prompt. Returns an empty
     * string when the input is empty or can't be parsed — callers should
     * fall back to the raw title/body they already have.
     */
    public function render(string $xml): string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return '';
        }

        try {
            $wrapped = '<root>' . $xml . '</root>';
            $root = new SimpleXMLElement($wrapped);
        } catch (Throwable) {
            return '';
        }

        $sections = [];

        foreach ($root->xpath('/root/issue') ?: [] as $issue) {
            $sections[] = $this->renderIssue($issue);
        }

        foreach ($root->xpath('/root/primary-directive-thread') ?: [] as $thread) {
            $sections[] = "## Primary directive\n\n" . $this->renderCommentThread($thread);
        }

        foreach ($root->xpath('/root/other-thread') ?: [] as $thread) {
            $sections[] = "## Other thread\n\n" . $this->renderCommentThread($thread);
        }

        foreach ($root->xpath('/root/guidance') ?: [] as $guidance) {
            $sections[] = $this->renderGuidance($guidance);
        }

        return trim(implode("\n\n", array_filter($sections, fn (string $s): bool => $s !== '')));
    }

    private function renderIssue(SimpleXMLElement $issue): string
    {
        $identifier = (string) ($issue['identifier'] ?? '');
        $title = trim((string) ($issue->title ?? ''));
        $lines = [];

        $lines[] = "# {$identifier}: {$title}";

        $meta = [];
        if ($team = trim((string) ($issue->team['name'] ?? ''))) {
            $meta[] = "**Team:** {$team}";
        }
        if ($project = trim((string) ($issue->project['name'] ?? ''))) {
            $meta[] = "**Project:** {$project}";
        }
        $labels = array_map('trim', array_map('strval', iterator_to_array($issue->label, false)));
        $labels = array_values(array_filter($labels, fn (string $l): bool => $l !== ''));
        if ($labels !== []) {
            $meta[] = '**Labels:** ' . implode(', ', $labels);
        }
        if ($parent = $issue->{'parent-issue'} ?? null) {
            $parentId = (string) ($parent['identifier'] ?? '');
            $parentTitle = trim((string) ($parent->title ?? ''));
            $meta[] = "**Parent issue:** {$parentId} — {$parentTitle}";
        }

        if ($meta !== []) {
            $lines[] = implode("\n", $meta);
        }

        if ($description = trim((string) ($issue->description ?? ''))) {
            $lines[] = "## Description\n\n{$description}";
        }

        return implode("\n\n", $lines);
    }

    private function renderCommentThread(SimpleXMLElement $thread): string
    {
        $blocks = [];
        foreach ($thread->comment as $comment) {
            $author = trim((string) ($comment['author'] ?? 'Unknown'));
            $when = trim((string) ($comment['created-at'] ?? ''));
            $body = trim((string) $comment);
            if ($body === '') {
                continue;
            }
            $blocks[] = "**{$author}** _({$when})_:\n\n{$body}";
        }

        return implode("\n\n---\n\n", $blocks);
    }

    private function renderGuidance(SimpleXMLElement $guidance): string
    {
        $rules = [];
        foreach ($guidance->{'guidance-rule'} as $rule) {
            $origin = trim((string) ($rule['origin'] ?? ''));
            $team = trim((string) ($rule['team-name'] ?? ''));
            $body = trim((string) $rule);
            if ($body === '') {
                continue;
            }
            $label = $team !== '' ? "{$origin} ({$team})" : $origin;
            $rules[] = "- _{$label}_: {$body}";
        }

        return $rules === [] ? '' : "## Guidance\n\n" . implode("\n", $rules);
    }
}
