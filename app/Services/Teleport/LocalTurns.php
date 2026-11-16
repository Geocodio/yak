<?php

namespace App\Services\Teleport;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Http\Resources\ThreadMessagesData;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use App\Support\Markdown;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The conversation turns that ran on the person's computer, one stretch per
 * teleport up, read from the transcript as it was uploaded then. Turns Yak ran
 * are already in the thread timeline and are cut off by the lengths recorded at
 * the teleports.
 *
 * A turn is a person prompt with Claude's replies and tool steps up to the next
 * prompt. Tool results that are not prompts, meta lines and subagent work stay
 * out of the turn count.
 *
 * @phpstan-type Step array{key: string, logId: null, category: string, tool: ?string, label: string, durationMs: null, exitCode: null, failed: bool, running: bool, retryPassed: bool, retried: bool, at: null, createdAt: null, content: array{input: string, output: ?string}}
 * @phpstan-type Item array{kind: 'text', key: string, html: string, model: ?string}|array{kind: 'tools', key: string, steps: list<Step>}
 * @phpstan-type Turn array{id: int, at: string, text: string, html: string, items: list<Item>}
 */
class LocalTurns
{
    private const int INITIAL = 10;

    private const int MAX_PAGE = 50;

    private const int OUTPUT_LIMIT = 4000;

    private const int LABEL_LIMIT = 300;

    private const array CATEGORIES = [
        'Bash' => 'command', 'Read' => 'read', 'Grep' => 'search', 'Glob' => 'search',
        'WebFetch' => 'fetch', 'WebSearch' => 'fetch',
        'Edit' => 'edit', 'MultiEdit' => 'edit', 'Write' => 'edit', 'NotebookEdit' => 'edit',
    ];

    public function __construct(private readonly SessionStore $store) {}

    /**
     * One entry per stretch on the person's computer, oldest first. `movedAt` is when the thread moved there, or null
     * for the stretch the thread began with, which belongs at the top.
     *
     * @return list<array{stintId: string, movedAt: ?string, count: int, firstPromptText: string, startedAt: string, endedAt: string, hasMore: bool, authorName: string, authorInitials: string, turns: list<Turn>}>
     */
    public function summaries(Thread $thread): array
    {
        $summaries = [];

        foreach ($this->stints($thread) as $stint) {
            $turns = $this->turns($thread, $stint);

            if ($turns === []) {
                continue;
            }

            $name = (string) $stint['up']->user?->name;
            $shown = array_slice($turns, -self::INITIAL);

            $summaries[] = [
                'stintId' => $stint['up']->id,
                'movedAt' => $stint['movedAt'],
                'count' => count($turns),
                'firstPromptText' => Str::limit($turns[0]['text'], 200),
                'startedAt' => $turns[0]['at'],
                'endedAt' => $turns[array_key_last($turns)]['at'],
                'hasMore' => count($turns) > count($shown),
                'authorName' => $name,
                'authorInitials' => ThreadMessagesData::initials($name),
                'turns' => $shown,
            ];
        }

        return $summaries;
    }

    /**
     * Up to $limit turns of one stretch that come before turn $before, oldest first.
     *
     * @return array{turns: list<Turn>, hasMore: bool}
     */
    public function before(Thread $thread, string $stintId, int $before, int $limit): array
    {
        $stint = collect($this->stints($thread))->first(fn (array $candidate): bool => $candidate['up']->id === $stintId);
        $earlier = array_values(array_filter($stint === null ? [] : $this->turns($thread, $stint), fn (array $turn): bool => $turn['id'] < $before));
        $page = array_slice($earlier, -max(1, min($limit, self::MAX_PAGE)));

        return ['turns' => $page, 'hasMore' => count($page) < count($earlier)];
    }

    /**
     * Each completed teleport up ends a stretch on the computer. It starts where the session file stood when the
     * thread last moved down to that computer, or at the top when the thread began there.
     *
     * @return list<array{up: Teleport, start: int, end: int, movedAt: ?string}>
     */
    private function stints(Thread $thread): array
    {
        $completed = $thread->teleports()->where('state', TeleportState::Completed)->oldest('id')->get();
        $stints = [];
        $down = null;

        foreach ($completed as $up) {
            if ($up->direction === TeleportDirection::Down) {
                $down = $up;

                continue;
            }

            $sessionId = (string) $up->session_id;
            $end = $this->lengthOf($up->payload['uploaded_files'] ?? [], $sessionId);

            if ($end === null || $sessionId === '') {
                continue;
            }

            $downSession = $down?->manifest['session'] ?? [];
            $start = ($downSession['session_id'] ?? null) === $sessionId
                ? ($this->lengthOf($downSession['files'] ?? [], $sessionId) ?? 0)
                : 0;

            $stints[] = ['up' => $up, 'start' => $start, 'end' => $end, 'movedAt' => $down?->completed_at?->toIso8601String()];
        }

        return $stints;
    }

    /**
     * @param  list<array{rel_path: string, length: int}>  $files
     */
    private function lengthOf(array $files, string $sessionId): ?int
    {
        foreach ($files as $file) {
            if ($file['rel_path'] === $sessionId . '.jsonl') {
                return (int) $file['length'];
            }
        }

        return null;
    }

    /**
     * @param  array{up: Teleport, start: int, end: int, movedAt: ?string}  $stint
     * @return list<Turn>
     */
    private function turns(Thread $thread, array $stint): array
    {
        $up = $stint['up'];
        $sessionId = (string) $up->session_id;

        return Cache::remember("local-turns:{$up->id}:{$stint['start']}", now()->addHour(), function () use ($thread, $sessionId, $stint): array {
            try {
                $bytes = substr($this->store->read($thread, $sessionId, $sessionId . '.jsonl'), $stint['start'], $stint['end'] - $stint['start']);
            } catch (Throwable) {
                return [];
            }

            return $this->parse($bytes);
        });
    }

    /**
     * @return list<Turn>
     */
    public function parse(string $jsonl): array
    {
        $turns = [];
        /** @var Turn|null $current */
        $current = null;
        /** @var array<string, array{int, int}> $steps */
        $steps = [];
        $turnId = 0;

        foreach (explode("\n", $jsonl) as $line) {
            $entry = json_decode($line, true);

            if (! is_array($entry) || ($entry['isSidechain'] ?? false) || ($entry['isMeta'] ?? false)) {
                continue;
            }

            $content = $entry['message']['content'] ?? null;

            if (($entry['type'] ?? null) === 'user') {
                $prompt = $this->promptText($content);

                if ($prompt !== null) {
                    if ($current !== null) {
                        $turns[] = $current;
                    }
                    $id = count($turns) + 1;
                    $turnId = $id;
                    $current = ['id' => $id, 'at' => (string) ($entry['timestamp'] ?? ''), 'text' => $prompt, 'html' => Markdown::toHtml($prompt), 'items' => []];
                    $steps = [];

                    continue;
                }

                foreach (is_array($content) ? $content : [] as $block) {
                    if (($block['type'] ?? null) === 'tool_result' && isset($steps[$block['tool_use_id'] ?? ''])) {
                        [$itemIndex, $stepIndex] = $steps[$block['tool_use_id']];
                        $current['items'][$itemIndex]['steps'][$stepIndex]['failed'] = (bool) ($block['is_error'] ?? false);
                        $current['items'][$itemIndex]['steps'][$stepIndex]['content']['output'] = $this->resultText($block['content'] ?? '');
                    }
                }

                continue;
            }

            if (($entry['type'] ?? null) !== 'assistant' || $current === null || ! is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                $type = $block['type'] ?? null;

                if ($type === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                    $text = SecretRedactor::redact((string) $block['text']);
                    $current['items'][] = ['kind' => 'text', 'key' => "local:{$turnId}:" . count($current['items']), 'html' => Markdown::toHtml($text), 'model' => $entry['message']['model'] ?? null];
                } elseif ($type === 'tool_use') {
                    $last = array_key_last($current['items']);

                    if ($last === null || ($current['items'][$last]['kind'] ?? null) !== 'tools') {
                        $current['items'][] = ['kind' => 'tools', 'key' => "local:{$turnId}:" . count($current['items']), 'steps' => []];
                        $last = array_key_last($current['items']);
                    }

                    $current['items'][$last]['steps'][] = $this->step((string) ($block['id'] ?? ''), (string) ($block['name'] ?? ''), (array) ($block['input'] ?? []));
                    $steps[(string) ($block['id'] ?? '')] = [$last, array_key_last($current['items'][$last]['steps'])];
                }
            }
        }

        if ($current !== null) {
            $turns[] = $current;
        }

        return $turns;
    }

    /**
     * The text of a real person prompt, or null for tool results, command echoes and interruptions.
     */
    private function promptText(mixed $content): ?string
    {
        $text = is_string($content)
            ? $content
            : implode("\n", array_map(fn (array $block): string => ($block['type'] ?? null) === 'text' ? (string) $block['text'] : '', array_filter((array) $content, 'is_array')));

        $text = trim($text);

        if ($text === '' || (is_array($content) && collect($content)->contains(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_result'))) {
            return null;
        }

        return preg_match('/^(<local-command-|<command-name>|<system-reminder>|Caveat:|\[Request interrupted)/', $text) === 1 ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return Step
     */
    private function step(string $id, string $name, array $input): array
    {
        $pick = fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $label = match ($name) {
            'Bash' => $pick('command'),
            'Grep', 'Glob' => $pick('pattern'),
            'WebFetch' => $pick('url'),
            'WebSearch' => $pick('query'),
            'Task', 'Agent' => 'Agent: ' . ($pick('description') ?: $pick('subagent_type')),
            default => $pick('file_path'),
        };
        $label = Str::limit(SecretRedactor::redact($label !== '' && $label !== 'Agent: ' ? $label : $name), self::LABEL_LIMIT);

        return [
            'key' => "local:tool:{$id}",
            'logId' => null,
            'category' => self::CATEGORIES[$name] ?? 'command',
            'tool' => $name,
            'label' => $label,
            'durationMs' => null,
            'exitCode' => null,
            'failed' => false,
            'running' => false,
            'retryPassed' => false,
            'retried' => false,
            'at' => null,
            'createdAt' => null,
            'content' => ['input' => $label, 'output' => null],
        ];
    }

    private function resultText(mixed $content): ?string
    {
        $text = is_string($content)
            ? $content
            : implode("\n", array_map(fn (mixed $block): string => is_array($block) ? (string) ($block['text'] ?? '') : '', (array) $content));

        return $text === '' ? null : Str::limit(SecretRedactor::redact($text), self::OUTPUT_LIMIT);
    }
}
