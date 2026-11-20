<?php

namespace App\Http\Resources;

use App\Enums\TaskRunKind;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Models\YakTask;
use App\Services\Threads\ThreadTasks;
use App\Services\Threads\ThreadTurns;
use App\Support\Markdown;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The full log of one run, in the entry shape of the transcript overlay. A run of a thread keeps its steps as the
 * thread's `entry` events between its first `turn` event and the first `turn` event of another run; a thread made
 * from an old task keeps them as task log rows. A page is cut in SQL after a cursor and may be narrowed by a search.
 *
 * @phpstan-type RunLogPage array{entries: list<array<string, mixed>>, nextCursor: ?int}
 */
final class ThreadRunLogData
{
    public const int PAGE_SIZE = 50;

    private const int FIELD_LIMIT_BYTES = 16384;

    /**
     * @return RunLogPage
     */
    public static function forRun(Thread $thread, TaskRun $run, int $cursor, ?string $search): array
    {
        $bounds = self::bounds($thread, $run->id);

        if ($bounds === null) {
            return ['entries' => [], 'nextCursor' => null];
        }

        $steps = self::runEvents($thread, $bounds)->where('sequence', '>', max($cursor, $bounds['start']))->where('payload->role', '!=', 'tool_result');

        if (filled($search)) {
            $sequences = array_slice(self::matchingSequences($thread, $bounds, clone $steps, $search), 0, self::PAGE_SIZE + 1);
            $page = self::runEvents($thread, $bounds)->whereIn('sequence', $sequences)->orderBy('sequence')->get();
        } else {
            $page = $steps->orderBy('sequence')->limit(self::PAGE_SIZE + 1)->get();
        }

        $hasMore = $page->count() > self::PAGE_SIZE;
        $page = $page->take(self::PAGE_SIZE)->values();
        $results = self::toolResults(fn (): Builder => self::runEvents($thread, $bounds), $page);
        $numbers = self::stepNumbers(fn (): Builder => self::runEvents($thread, $bounds), $page);

        return [
            'entries' => array_values($page->map(fn (ThreadEvent $event): array => self::capped([...self::eventEntry($event, $results), 'number' => $numbers[$event->sequence] ?? null]))->all()),
            'nextCursor' => $hasMore ? $page->last()?->sequence : null,
        ];
    }

    /**
     * The log of one agent or workflow: its own entries across turns, a page of 50 steps after a cursor.
     * Claude's own run log leaves these entries out.
     *
     * @return RunLogPage
     */
    public static function forAgent(Thread $thread, ThreadAgent $agent, int $cursor): array
    {
        $ids = array_values(array_filter([$agent->task_id, $agent->tool_use_id]));
        $events = fn (): Builder => ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'entry')->whereIn('payload->agentId', $ids);

        $page = $events()->where('sequence', '>', $cursor)->where('payload->role', '!=', 'tool_result')->orderBy('sequence')->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $page->count() > self::PAGE_SIZE;
        $page = $page->take(self::PAGE_SIZE)->values();
        $results = self::toolResults($events, $page);
        $numbers = self::stepNumbers($events, $page);

        return [
            'entries' => array_values($page->map(fn (ThreadEvent $event): array => self::capped([...self::eventEntry($event, $results), 'number' => $numbers[$event->sequence] ?? null]))->all()),
            'nextCursor' => $hasMore ? $page->last()?->sequence : null,
        ];
    }

    /**
     * @return RunLogPage
     */
    public static function forTask(YakTask $task, int $cursor, ?string $search): array
    {
        $logs = TaskLog::query()->where('yak_task_id', $task->id)->where('id', '>', $cursor);

        if (filled($search)) {
            $logs->whereRaw("message like ? escape '|'", [self::likePattern($search, false)]);
        }

        $page = $logs->orderBy('id')->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $page->count() > self::PAGE_SIZE;
        $page = $page->take(self::PAGE_SIZE)->values();

        return [
            'entries' => array_values($page->map(fn (TaskLog $log): array => self::capped(TranscriptData::entry($log)))->all()),
            'nextCursor' => $hasMore ? $page->last()?->id : null,
        ];
    }

    /**
     * The runs that have events, each with where its answer ends, so the page can offer a full log after it.
     *
     * @return list<array{runId: string, afterSequence: int, durationSeconds: int, stepCount: int, isRetry: bool, model: ?string, isActive: bool, startedAt: ?string}>
     */
    public static function runs(Thread $thread): array
    {
        if ($thread->is_legacy) {
            return [];
        }

        ['runs' => $runs, 'ends' => $ends] = ThreadMilestonesData::boundaries($thread);
        $summaries = [];

        foreach ($runs as $run) {
            if (isset($ends[$run->id])) {
                $summaries[] = [
                    'runId' => (string) $run->id,
                    'afterSequence' => $ends[$run->id],
                    'durationSeconds' => intdiv((int) $run->total_ms, 1000),
                    'stepCount' => (int) $run->num_turns,
                    'isRetry' => $run->kind === TaskRunKind::Retry,
                    'model' => $run->model,
                    'isActive' => in_array($run->turn_state, ThreadTurns::ACTIVE_STATES, true),
                    'startedAt' => ($run->started_at ?? $run->created_at)?->toIso8601String(),
                ];
            }
        }

        return $summaries;
    }

    /**
     * The run and entry a `?log=` entry id names, or null when the thread has no such entry.
     *
     * @return array{runId: string, entryId: int}|null
     */
    public static function locate(Thread $thread, int $entryId): ?array
    {
        if ($thread->is_legacy) {
            $taskId = TaskLog::query()->whereKey($entryId)->value('yak_task_id');
            $task = $taskId === null ? null : ThreadTasks::request($thread)?->conversation()->firstWhere('id', $taskId);

            return $task === null ? null : ['runId' => $task->public_id, 'entryId' => $entryId];
        }

        $isEntry = ThreadEvent::query()->where('thread_id', $thread->id)->where('sequence', $entryId)->where('type', 'entry')->exists();
        $runId = $isEntry ? self::turnStarts($thread)->where('sequence', '<', $entryId)->orderByDesc('sequence')->first()?->payload['turnId'] ?? null : null;

        return is_int($runId) ? ['runId' => (string) $runId, 'entryId' => $entryId] : null;
    }

    public static function belongsToThread(Thread $thread, YakTask $task): bool
    {
        return $thread->is_legacy && ThreadTasks::request($thread)?->conversation()->contains('id', $task->id) === true;
    }

    /**
     * @return Builder<ThreadEvent>
     */
    private static function turnStarts(Thread $thread): Builder
    {
        return ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'turn')->where('payload->state', 'running');
    }

    /**
     * The sequences a run spans: from its first `running` event up to the first `running` event of another run.
     *
     * @return array{start: int, end: ?int}|null
     */
    private static function bounds(Thread $thread, int $runId): ?array
    {
        $start = self::turnStarts($thread)->where('payload->turnId', $runId)->min('sequence');

        if ($start === null) {
            return null;
        }

        $end = self::turnStarts($thread)->where('payload->turnId', '!=', $runId)->where('sequence', '>', $start)->min('sequence');

        return ['start' => (int) $start, 'end' => $end === null ? null : (int) $end];
    }

    /**
     * @param  array{start: int, end: ?int}  $bounds
     * @return Builder<ThreadEvent>
     */
    private static function runEvents(Thread $thread, array $bounds): Builder
    {
        return ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'entry')
            ->when(ThreadAgent::isEnabled(), fn (Builder $query) => $query->whereNull('payload->agentId'))
            ->where('sequence', '>', $bounds['start'])
            ->when($bounds['end'] !== null, fn (Builder $query) => $query->where('sequence', '<', $bounds['end']));
    }

    /**
     * The result events of the tool calls on a page, by tool call id.
     *
     * @param  Closure(): Builder<ThreadEvent>  $events  the entries the page was cut from
     * @param  Collection<int, ThreadEvent>  $page
     * @return array<string, array{text: string, isError: bool, at: CarbonInterface}>
     */
    private static function toolResults(Closure $events, Collection $page): array
    {
        $ids = $page->flatMap(fn (ThreadEvent $event): array => array_column(self::toolCalls($event), 'id'))->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $results = [];

        foreach ($events()->where('payload->role', 'tool_result')->whereIn('payload->toolUseId', $ids)->orderBy('sequence')->get() as $event) {
            $results[(string) $event->payload['toolUseId']] = ['text' => (string) ($event->payload['text'] ?? ''), 'isError' => (bool) ($event->payload['isError'] ?? false), 'at' => $event->created_at];
        }

        return $results;
    }

    /**
     * The step number of each entry on a page (its place among all steps of the run), by sequence: one count of the
     * steps before the page and one read of the step sequences inside the page's range, so a page costs two small
     * queries however long the run is.
     *
     * @param  Closure(): Builder<ThreadEvent>  $events  the entries the page was cut from
     * @param  Collection<int, ThreadEvent>  $page
     * @return array<int, int>
     */
    private static function stepNumbers(Closure $events, Collection $page): array
    {
        if ($page->isEmpty()) {
            return [];
        }

        $first = (int) $page->first()->sequence;
        $before = $events()->where('payload->role', '!=', 'tool_result')->where('sequence', '<', $first)->count();
        $inRange = $events()->where('payload->role', '!=', 'tool_result')->whereBetween('sequence', [$first, (int) $page->last()->sequence])->orderBy('sequence')->pluck('sequence')->all();

        return array_map(fn (int $index): int => $before + $index + 1, array_flip(array_map('intval', $inRange)));
    }

    /**
     * The sequences of the steps after the cursor that match a search, in order, at most a page and one more: a step
     * matches by its own text, or by the output of its tool call. The output rows are matched in one scan that reads
     * only their tool-use ids, and the call events are walked in sequence order in batches of 100 until a page and one
     * more have matched, so the work stops with the page however long the run is. The page is the first of the merged
     * lists and the cursor reaches the rest.
     *
     * @param  array{start: int, end: ?int}  $bounds
     * @param  Builder<ThreadEvent>  $steps  the steps after the cursor
     * @return list<int>
     */
    private static function matchingSequences(Thread $thread, array $bounds, Builder $steps, string $search): array
    {
        $limit = self::PAGE_SIZE + 1;
        $pattern = self::likePattern($search);
        $own = (clone $steps)->whereRaw("payload like ? escape '|'", [$pattern])->orderBy('sequence')->limit($limit)->pluck('sequence')->map(fn (mixed $sequence): int => (int) $sequence)->all();
        $callIds = self::runEvents($thread, $bounds)->where('payload->role', 'tool_result')->whereRaw("payload like ? escape '|'", [$pattern])->select('payload->toolUseId as call_id')->pluck('call_id')
            ->map(fn (mixed $id): string => trim((string) $id, '"'))->filter()->flip();
        $byOutput = [];

        if ($callIds->isNotEmpty()) {
            foreach ($steps->where('payload->role', 'assistant')->select(['sequence', 'payload'])->lazyById(100, 'sequence') as $event) {
                if (collect(self::toolCalls($event))->contains(fn (array $call): bool => $callIds->has((string) ($call['id'] ?? '')))) {
                    $byOutput[] = (int) $event->sequence;

                    if (count($byOutput) >= $limit) {
                        break;
                    }
                }
            }
        }

        $sequences = array_values(array_unique([...$own, ...$byOutput]));
        sort($sequences);

        return array_slice($sequences, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toolCalls(ThreadEvent $event): array
    {
        return array_values(array_filter((array) ($event->payload['blocks'] ?? []), fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use'));
    }

    /**
     * Cuts the long text fields of an entry at 16 KB so a page of large steps stays small.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function capped(array $entry): array
    {
        foreach (['input', 'output', 'fullText', 'html'] as $field) {
            if (isset($entry[$field]) && is_string($entry[$field]) && strlen($entry[$field]) > self::FIELD_LIMIT_BYTES) {
                $entry[$field] = mb_strcut($entry[$field], 0, self::FIELD_LIMIT_BYTES);
                $entry['isTruncated'] = true;
            }
        }

        return $entry;
    }

    /**
     * Matches the way the payload is stored (JSON text), so a slash or an accented letter is found as typed.
     */
    private static function likePattern(string $search, bool $asJson = true): string
    {
        $term = $asJson ? substr((string) json_encode($search), 1, -1) : $search;

        return '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $term) . '%';
    }

    /**
     * @param  array<string, array{text: string, isError: bool, at: CarbonInterface}>  $results
     * @return array<string, mixed>
     */
    private static function eventEntry(ThreadEvent $event, array $results): array
    {
        $payload = $event->payload;
        $base = ['id' => $event->sequence, 'badge' => null, 'text' => '', 'at' => $event->created_at->format('g:i:s A'), 'kind' => 'level', 'error' => false, 'milestone' => false];
        $calls = self::toolCalls($event);

        return match (true) {
            $calls !== [] => self::toolEntry($event, $base, $calls, $results),
            ($payload['role'] ?? null) === 'assistant' => self::assistantEntry($base, $payload),
            ($payload['role'] ?? null) === 'user' => [...$base, 'badge' => 'prompt', 'kind' => 'prompt', 'text' => Str::limit(Markdown::toPlainText((string) ($payload['text'] ?? '')), 200), 'prompt' => ['user' => (string) ($payload['text'] ?? ''), 'system' => '', 'meta' => []]],
            ($payload['role'] ?? null) === 'result' => self::resultEntry($base, $payload),
            ($payload['role'] ?? null) === 'tool_result' => [...$base, 'badge' => 'output', 'text' => Str::limit(Str::before((string) ($payload['text'] ?? ''), "\n"), 200), 'error' => (bool) ($payload['isError'] ?? false), 'details' => [['label' => 'Output', 'value' => (string) ($payload['text'] ?? ''), 'error' => (bool) ($payload['isError'] ?? false)]]],
            default => [...$base, 'badge' => 'system', 'text' => 'Session started', 'details' => self::details(['Model' => $payload['model'] ?? null, 'Session' => $payload['sessionId'] ?? null])],
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $calls
     * @param  array<string, array{text: string, isError: bool, at: CarbonInterface}>  $results
     * @return array<string, mixed>
     */
    private static function toolEntry(ThreadEvent $event, array $base, array $calls, array $results): array
    {
        $first = $calls[0];
        $name = (string) ($first['name'] ?? 'tool');
        $input = (array) ($first['input'] ?? []);
        $subject = (string) ($input['command'] ?? $input['file_path'] ?? $input['pattern'] ?? $input['url'] ?? $input['description'] ?? '');
        $found = array_values(array_filter(array_map(fn (array $call): ?array => $results[(string) ($call['id'] ?? '')] ?? null, $calls)));

        return [
            ...$base,
            'badge' => $name,
            'kind' => 'tool',
            'tool' => $name,
            'text' => Str::limit(trim($name . ' ' . $subject), 200),
            'subject' => Str::limit($subject, 500),
            'isShellCommand' => count($calls) === 1 && $name === 'Bash' && isset($input['command']),
            'durationSeconds' => $found === [] ? null : (int) abs($event->created_at->diffInSeconds(max(array_column($found, 'at')))),
            'error' => in_array(true, array_column($found, 'isError'), true),
            'input' => count($calls) === 1 && $name === 'Bash' && isset($input['command'])
                ? (string) $input['command']
                : json_encode(count($calls) === 1 ? $input : array_map(fn (array $call): array => ['name' => $call['name'] ?? null, 'input' => $call['input'] ?? null], $calls), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'output' => $found === [] ? null : implode("\n", array_column($found, 'text')),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function assistantEntry(array $base, array $payload): array
    {
        $blocks = array_values(array_filter((array) ($payload['blocks'] ?? []), fn (mixed $block): bool => is_array($block) && in_array($block['type'] ?? null, ['text', 'thinking'], true)));
        $text = trim(implode("\n\n", array_map(fn (array $block): string => (string) ($block['text'] ?? ''), $blocks)));

        return [...$base, 'badge' => ($blocks[0]['type'] ?? null) === 'thinking' ? 'thinking' : null, 'kind' => 'assistant', 'text' => Str::limit(Markdown::toPlainText($text), 200), 'html' => Markdown::toHtml($text), 'fullText' => $text];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function resultEntry(array $base, array $payload): array
    {
        $isError = (bool) ($payload['isError'] ?? false);

        return [
            ...$base,
            'badge' => $isError ? 'error' : 'result',
            'text' => $isError ? 'The run ended with an error' : 'The run finished',
            'error' => $isError,
            'milestone' => true,
            'details' => self::details([
                'Result' => $payload['text'] ?? null,
                'Cost' => isset($payload['costUsd']) ? '$' . number_format((float) $payload['costUsd'], 2) : null,
                'Duration' => isset($payload['durationMs']) ? round((int) $payload['durationMs'] / 1000) . 's' : null,
                'Steps' => $payload['numTurns'] ?? null,
            ], $isError),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{label: string, value: string, error: bool}>
     */
    private static function details(array $values, bool $isError = false): array
    {
        $details = [];

        foreach ($values as $label => $value) {
            if ($value !== null && $value !== '') {
                $details[] = ['label' => (string) $label, 'value' => (string) $value, 'error' => $isError && $label === 'Result'];
            }
        }

        return $details;
    }
}
