<?php

namespace App\Http\Resources;

use App\Models\TaskLog;
use App\Models\YakTask;
use App\Support\Markdown;
use Illuminate\Support\Collection;

/**
 * Full transcript entries (input/output/prompt) for the run+attempt shown
 * in the sidebar, sent as an `Inertia::optional` prop -- the activity log
 * only needs the flattened summary row, the transcript overlay needs the
 * full tool input/output and prompt text behind it.
 */
final class TranscriptData
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function for(YakTask $run, int $attempt): array
    {
        /** @var Collection<int, TaskLog> $logs */
        $logs = $run->logs()
            ->where('attempt_number', $attempt)
            ->orderBy('created_at')
            ->get();

        return $logs->map(fn (TaskLog $log): array => self::entry($log))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(TaskLog $log): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = (array) $log->metadata;
        $type = $metadata['type'] ?? null;

        $row = ActivityLogData::isMilestone($log);

        [$kind, $badge] = match (true) {
            $type === 'tool_use' => ['tool', (string) ($metadata['tool'] ?? 'tool')],
            $type === 'prompt' => ['prompt', 'prompt'],
            $type === 'assistant' => ['assistant', null],
            default => ['level', $log->level],
        };

        $entry = [
            'id' => $log->id,
            'badge' => $badge,
            'text' => Markdown::toPlainText($log->message),
            'at' => $log->created_at->format('g:i:s A'),
            'kind' => $kind,
            'error' => (bool) ($metadata['is_error'] ?? false),
            'milestone' => $row,
        ];

        if ($type === 'tool_use') {
            $input = $metadata['input'] ?? null;
            $tool = (string) ($metadata['tool'] ?? '');
            $entry['tool'] = $tool;
            // Bash calls show the raw command line rather than the whole
            // input object as JSON, matching the old Blade transcript partial.
            $entry['input'] = match (true) {
                $tool === 'Bash' && is_array($input) && isset($input['command']) => (string) $input['command'],
                is_array($input) => json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                default => null,
            };
            $entry['output'] = isset($metadata['output']) ? (string) $metadata['output'] : null;
        }

        if ($type === 'prompt') {
            $entry['prompt'] = [
                'user' => (string) ($metadata['prompt'] ?? ''),
                'system' => (string) ($metadata['system_prompt'] ?? ''),
                'meta' => array_filter([
                    'model' => isset($metadata['model']) ? (string) $metadata['model'] : null,
                    'max_turns' => isset($metadata['max_turns']) ? (string) $metadata['max_turns'] : null,
                    'max_budget_usd' => isset($metadata['max_budget_usd']) ? (string) $metadata['max_budget_usd'] : null,
                    'resume_session_id' => isset($metadata['resume_session_id']) ? (string) $metadata['resume_session_id'] : null,
                ], fn (?string $value) => $value !== null),
            ];
        }

        return $entry;
    }
}
