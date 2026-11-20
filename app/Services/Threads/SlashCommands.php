<?php

namespace App\Services\Threads;

use App\Models\Repository;
use App\Models\Thread;

/**
 * The slash commands the thread composer offers: Yak's own, plus the skills and
 * commands Claude lists when a session starts. The list is saved at that
 * moment, on the thread and on its repository, so a thread that has not run yet
 * shows what the repository's last session had.
 *
 * @phpstan-type Command array{name: string, description: string, argumentHint: ?string, kind: 'yak'|'skill'|'command'}
 */
class SlashCommands
{
    /**
     * Commands Yak handles itself. They win a name collision with Claude's list.
     *
     * @var list<Command>
     */
    public const array YAK = [
        ['name' => 'clear', 'description' => 'Start a fresh conversation on a new thread, linked back to this one', 'argumentHint' => null, 'kind' => 'yak'],
    ];

    /**
     * Built-in commands that open a terminal screen or change the local terminal, which a thread cannot show.
     *
     * @var list<string>
     */
    private const array INTERACTIVE_BUILTINS = [
        'agents', 'add-dir', 'auto-mode-setup', 'bug', 'color', 'config', 'copy', 'doctor', 'exit', 'export', 'fast', 'feedback', 'focus', 'heapdump', 'help', 'hooks', 'ide', 'keybindings', 'login', 'logout', 'mcp', 'memory', 'model', 'output-style', 'permissions', 'privacy-settings', 'quit', 'release-notes', 'reload-plugins', 'rename', 'resume', 'statusline', 'status', 'terminal-setup', 'theme', 'vim',
    ];

    /**
     * Saves the list a runner `commands` line carries, minus what a thread cannot use.
     *
     * @param  array<string, mixed>  $line
     */
    public function capture(Thread $thread, array $line): void
    {
        $commands = self::usable($line);

        if ($commands === null || $commands === []) {
            return;
        }

        $attributes = ['slash_commands' => $commands, 'slash_commands_captured_at' => now()];

        Thread::query()->whereKey($thread->id)->update(self::asJson($attributes));
        $thread->forceFill($attributes)->syncOriginal();
        Repository::query()->where('slug', $thread->repo)->update(self::asJson($attributes));
    }

    /**
     * Yak's commands, then Claude's list for this thread, or its repository's last known list.
     *
     * @return array{commands: list<Command>, capturedAt: ?string}
     */
    public function forThread(Thread $thread): array
    {
        $source = $thread;

        if ($thread->slash_commands === null) {
            $source = Repository::query()->where('slug', $thread->repo)->first(['slash_commands', 'slash_commands_captured_at']) ?? $thread;
        }

        $reserved = array_column(self::YAK, 'name');
        $claude = array_values(array_filter($source->slash_commands ?? [], fn (array $command): bool => ! in_array($command['name'], $reserved, true)));

        return [
            'commands' => [...self::YAK, ...$claude],
            'capturedAt' => $source->slash_commands_captured_at?->toIso8601String(),
        ];
    }

    /**
     * Whether the text starts with a command the thread lists.
     */
    public function startsWithKnownCommand(Thread $thread, string $text): bool
    {
        if (! preg_match('#^/([^\s/]+)(\s|$)#', ltrim($text), $match)) {
            return false;
        }

        return in_array($match[1], array_column($this->forThread($thread)['commands'], 'name'), true);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array{name: string, description: string, argumentHint: ?string, kind: 'skill'|'command'}>|null
     */
    private static function usable(array $line): ?array
    {
        if (! is_array($line['commands'] ?? null)) {
            return null;
        }

        $skills = array_filter((array) ($line['skills'] ?? []), 'is_string');
        $terminal = array_filter((array) ($line['terminal'] ?? []), 'is_string');
        $usable = [];

        foreach ($line['commands'] as $command) {
            $name = is_array($command) ? ($command['name'] ?? null) : null;

            if (! is_string($name) || $name === '' || isset($usable[$name]) || in_array($name, $terminal, true)) {
                continue;
            }

            $isBuiltin = ($command['builtin'] ?? false) === true;

            if ($isBuiltin && in_array($name, self::INTERACTIVE_BUILTINS, true)) {
                continue;
            }

            $hint = $command['argumentHint'] ?? null;
            $usable[$name] = [
                'name' => $name,
                'description' => is_string($command['description'] ?? null) ? $command['description'] : '',
                'argumentHint' => is_string($hint) && $hint !== '' ? $hint : null,
                'kind' => ! $isBuiltin && in_array($name, $skills, true) ? 'skill' : 'command',
            ];
        }

        return array_values($usable);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function asJson(array $attributes): array
    {
        return [...$attributes, 'slash_commands' => json_encode($attributes['slash_commands'], JSON_THROW_ON_ERROR)];
    }
}
