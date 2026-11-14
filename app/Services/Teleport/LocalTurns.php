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
 * The conversation turns that ran on the person's computer before the latest
 * teleport up, read from the transcript as it was uploaded then. Turns Yak ran
 * afterwards are already in the thread timeline and are cut off by the length
 * recorded at that teleport.
 *
 * A turn is a person prompt with Claude's replies and tool steps up to the next
 * prompt. Tool results that are not prompts, meta lines and subagent work stay
 * out of the turn count.
 *
 * ponytail: only the latest stretch is shown; a session that moved up more than
 * once has no clean boundary between its stretches, so it shows none.
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
     * @return array{count: int, firstPromptText: string, startedAt: string, endedAt: string, hasMore: bool, authorName: string, authorInitials: string, turns: list<Turn>}|null
     */
    public function summary(Thread $thread): ?array
    {
        $up = $this->latestUp($thread);
        $turns = $up === null ? [] : $this->turns($thread, $up);

        if ($turns === []) {
            return null;
        }

        $name = (string) $up?->user?->name;
        $shown = array_slice($turns, -self::INITIAL);

        return [
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

    /**
     * Up to $limit turns that come before turn $before, oldest first.
     *
     * @return array{turns: list<Turn>, hasMore: bool}
     */
    public function before(Thread $thread, int $before, int $limit): array
    {
        $up = $this->latestUp($thread);
        $earlier = array_values(array_filter($up === null ? [] : $this->turns($thread, $up), fn (array $turn): bool => $turn['id'] < $before));
        $page = array_slice($earlier, -max(1, min($limit, self::MAX_PAGE)));

        return ['turns' => $page, 'hasMore' => count($page) < count($earlier)];
    }

    private function latestUp(Thread $thread): ?Teleport
    {
        $ups = $thread->teleports()->where('direction', TeleportDirection::Up)->where('state', TeleportState::Completed)->oldest('id')->get();
        $latest = $ups->last();

        if ($latest === null || $ups->where('session_id', $latest->session_id)->count() > 1) {
            return null;
        }

        return $latest;
    }

    /**
     * @return list<Turn>
     */
    private function turns(Thread $thread, Teleport $up): array
    {
        $sessionId = (string) $up->session_id;
        $path = $sessionId . '.jsonl';
        $length = collect($up->payload['uploaded_files'] ?? [])->firstWhere('rel_path', $path)['length'] ?? null;

        if ($length === null || $sessionId === '') {
            return [];
        }

        return Cache::remember("local-turns:{$up->id}", now()->addHour(), function () use ($thread, $sessionId, $path, $length): array {
            try {
                $bytes = substr($this->store->read($thread, $sessionId, $path), 0, (int) $length);
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
        $current = null;
        $steps = [];

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
                    $current['items'][] = ['kind' => 'text', 'key' => "local:{$current['id']}:" . count($current['items']), 'html' => Markdown::toHtml($text), 'model' => $entry['message']['model'] ?? null];
                } elseif ($type === 'tool_use') {
                    $last = array_key_last($current['items']);

                    if ($last === null || $current['items'][$last]['kind'] !== 'tools') {
                        $current['items'][] = ['kind' => 'tools', 'key' => "local:{$current['id']}:" . count($current['items']), 'steps' => []];
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
