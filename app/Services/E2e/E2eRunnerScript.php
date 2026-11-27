<?php

namespace App\Services\E2e;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Decides what the fake broker writes for each command, from the recorded runner streams.
 * Turn lines come from natural-turn-end; a turn whose text contains HOLD_MARKER plays the
 * prefix of interrupt-result-without-result-key and waits; one containing STREAM_STOPPED_MARKER plays streamed-interrupt; one containing STREAM_MARKER plays streamed-reply, whose
 * stream_event lines carry the text as it was typed; interrupt plays that file's tail;
 * a steer plays the second command's queued and started lines of steer-during-final-text.
 * Every recorded command uuid is replaced by the command's own uuid.
 *
 * The tail of the interrupted turn leaves out the runner's exit lines, so the runner stays
 * live and the next turn of the thread needs no new start.
 */
final class E2eRunnerScript
{
    public const string HOLD_MARKER = '(hold this turn)';

    public const string STREAM_MARKER = '(stream this turn)';

    public const string STREAM_STOPPED_MARKER = '(stream and stop this turn)';

    public const int HOLD_BOUND_SECONDS = 120;

    public const int MAX_FIXTURE_BYTES = 1_048_576;

    public const int MAX_FIXTURE_LINES = 2000;

    private const int TURN_CAP_MILLISECONDS = 30_000;

    private const string NATURAL = 'natural-turn-end';

    private const string INTERRUPT = 'interrupt-result-without-result-key';

    private const string STEER = 'steer-during-final-text';

    private const string STREAMED = 'streamed-reply';

    private const string STREAMED_STOPPED = 'streamed-interrupt';

    private const string RECORDED_STREAMED_UUID = '00000000-0000-4000-8000-000000000001';

    private const string RECORDED_STEER_UUID = 'da1213d2-e404-5255-85f1-5d09e68a921a';

    private readonly string $fixtureDirectory;

    /** @var array<string, list<array<string, mixed>>> */
    private array $fixtures = [];

    /** @var array<string, true> */
    private array $liveRunners = [];

    /** @var array<string, array{uuid: string, since: CarbonImmutable}> */
    private array $held = [];

    /** @var array<string, int> epoch milliseconds when the channel's last scheduled line is due */
    private array $busyUntil = [];

    public function __construct(?string $fixtureDirectory = null, private readonly float $pace = 1.0)
    {
        $this->fixtureDirectory = $fixtureDirectory ?? base_path('tests/Fixtures/runner-streams');
    }

    /**
     * @param  array<string, mixed>  $command  one decoded `{channel}:in` command
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     *                                                                                           in order, times relative to now; empty for a command that writes nothing (set_model, tool_result) or that is malformed
     */
    public function respond(string $channel, array $command, int $generation): array
    {
        $type = $command['type'] ?? null;
        $uuid = $command['uuid'] ?? null;

        $lines = match ($type) {
            'start_runner' => $this->start($channel, $generation),
            'turn', 'steer' => is_string($uuid) && $uuid !== '' ? $this->message($channel, $type, $uuid, $command) : [],
            'interrupt' => $this->interrupt($channel),
            'end' => $this->end($channel),
            'kill' => $this->kill($channel),
            default => [],
        };

        return $this->afterQueued($channel, in_array($type, ['start_runner', 'end', 'kill'], true), $lines);
    }

    /**
     * A channel's lines are written in the order they are scheduled, so a new command's lines are
     * timed after the channel's last scheduled line. A start, end or kill clears the broker's queue.
     *
     * @param  list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>  $lines
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function afterQueued(string $channel, bool $clearsQueue, array $lines): array
    {
        $nowMilliseconds = CarbonImmutable::now()->getTimestampMs();
        $offset = $clearsQueue ? 0 : max(0, ($this->busyUntil[$channel] ?? 0) - $nowMilliseconds);
        $lastDue = $nowMilliseconds;

        foreach ($lines as $index => $scheduled) {
            $lines[$index]['afterMilliseconds'] += $offset;
            $lastDue = max($lastDue, $nowMilliseconds + $lines[$index]['afterMilliseconds']);
        }

        $this->busyUntil[$channel] = $lastDue;

        return $lines;
    }

    /** True while the channel's turn is held. */
    public function isHolding(string $channel): bool
    {
        return isset($this->held[$channel]);
    }

    /** True between this instance's start_runner on the channel and its end or kill. A new instance (a new process) has none. */
    public function hasRunner(string $channel): bool
    {
        return isset($this->liveRunners[$channel]);
    }

    /**
     * Lines that end held turns whose bound passed, with the natural end of the turn.
     *
     * @return array<string, list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>> keyed by channel
     */
    public function releaseExpired(DateTimeInterface $now): array
    {
        $released = [];

        foreach ($this->held as $channel => $hold) {
            if ($now->getTimestamp() - $hold['since']->getTimestamp() < self::HOLD_BOUND_SECONDS) {
                continue;
            }

            unset($this->held[$channel]);
            $released[$channel] = $this->afterQueued($channel, false, $this->naturalEnd($hold['uuid']));
        }

        return $released;
    }

    /**
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function start(string $channel, int $generation): array
    {
        $this->liveRunners[$channel] = true;
        unset($this->held[$channel]);

        return [
            $this->scripted(['type' => 'broker.runner_started', 'generation' => $generation], fromBroker: true),
            $this->scripted($this->runnerLine(self::NATURAL, 'started')),
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function message(string $channel, string $type, string $uuid, array $command): array
    {
        if (! $this->hasRunner($channel)) {
            return [$this->scripted(['type' => 'broker.no_runner', 'uuid' => $uuid], fromBroker: true)];
        }

        if ($type === 'steer' && $this->isHolding($channel)) {
            return $this->steerLines($uuid);
        }

        unset($this->held[$channel]);

        if (str_contains((string) ($command['text'] ?? ''), self::HOLD_MARKER)) {
            $this->held[$channel] = ['uuid' => $uuid, 'since' => CarbonImmutable::now()];

            return $this->timed($this->heldPrefix(), $uuid, 0);
        }

        if (str_contains((string) ($command['text'] ?? ''), self::STREAM_STOPPED_MARKER)) {
            return $this->timed($this->turnLines(self::STREAMED_STOPPED), $uuid, 0, self::RECORDED_STREAMED_UUID);
        }

        if (str_contains((string) ($command['text'] ?? ''), self::STREAM_MARKER)) {
            return $this->timed($this->turnLines(self::STREAMED), $uuid, 0, self::RECORDED_STREAMED_UUID);
        }

        return $this->timed($this->turnLines(self::NATURAL), $uuid, 0);
    }

    /**
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function interrupt(string $channel): array
    {
        if (! $this->isHolding($channel)) {
            return [];
        }

        $uuid = $this->held[$channel]['uuid'];
        unset($this->held[$channel]);
        $lines = $this->fixture(self::INTERRUPT);
        $assistantIndex = $this->firstAssistantIndex($lines);
        $tail = array_values(array_filter(
            array_slice($lines, $assistantIndex + 1),
            fn (array $line): bool => ! in_array($line['event'] ?? null, ['exit_after_result', 'done'], true),
        ));

        return $this->timed($tail, $uuid, (int) ($lines[$assistantIndex]['_ms'] ?? 0));
    }

    /**
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function end(string $channel): array
    {
        unset($this->liveRunners[$channel], $this->held[$channel]);

        return [
            $this->scripted($this->runnerLine(self::NATURAL, 'done')),
            $this->scripted(['type' => 'broker.runner_exited', 'exit_code' => 0], fromBroker: true),
        ];
    }

    /**
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function kill(string $channel): array
    {
        unset($this->liveRunners[$channel], $this->held[$channel]);

        return [$this->scripted(['type' => 'broker.kill_done'], fromBroker: true)];
    }

    /**
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function steerLines(string $uuid): array
    {
        $lines = array_values(array_filter(
            $this->fixture(self::STEER),
            fn (array $line): bool => ($line['command_uuid'] ?? null) === self::RECORDED_STEER_UUID && in_array($line['state'] ?? null, ['queued', 'started'], true),
        ));

        return $this->timed($lines, $uuid, (int) ($lines[0]['_ms'] ?? 0), self::RECORDED_STEER_UUID);
    }

    /**
     * The held turn's natural end: the final text, the result and the completed lifecycle line.
     *
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function naturalEnd(string $uuid): array
    {
        $lines = $this->turnLines(self::NATURAL);
        $assistantIndex = $this->firstAssistantIndex($lines);

        return $this->timed(array_slice($lines, $assistantIndex), $uuid, (int) ($lines[$assistantIndex]['_ms'] ?? 0));
    }

    /**
     * The fixture's lines without its runner lines.
     *
     * @return list<array<string, mixed>>
     */
    private function turnLines(string $name): array
    {
        return array_values(array_filter($this->fixture($name), fn (array $line): bool => ($line['type'] ?? null) !== 'runner'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function heldPrefix(): array
    {
        $lines = $this->turnLines(self::INTERRUPT);

        return array_slice($lines, 0, $this->firstAssistantIndex($lines) + 1);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function firstAssistantIndex(array $lines): int
    {
        foreach ($lines as $index => $line) {
            if (($line['type'] ?? null) === 'assistant') {
                return $index;
            }
        }

        throw new RuntimeException('A recorded runner stream has no assistant line.');
    }

    /**
     * Retargets the lines at the command's uuid and times them from `$originMilliseconds`.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>
     */
    private function timed(array $lines, string $uuid, int $originMilliseconds, string $recordedUuid = 'f429399e-374d-5e1e-a7cc-65fce3bd2a28'): array
    {
        $scripted = [];
        $previous = 0;

        foreach ($lines as $line) {
            $milliseconds = (int) ($line['_ms'] ?? 0);
            $after = min(self::TURN_CAP_MILLISECONDS, max($previous, (int) round(max(0, $milliseconds - $originMilliseconds) * $this->pace)));
            $previous = $after;
            $retargeted = json_decode(str_replace($recordedUuid, substr((string) json_encode($uuid), 1, -1), (string) json_encode($line)), true);
            $scripted[] = $this->scripted($retargeted, $after);
        }

        return $scripted;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}
     */
    private function scripted(array $line, int $afterMilliseconds = 0, bool $fromBroker = false): array
    {
        foreach (array_keys($line) as $key) {
            if ($key === '_ms' || str_starts_with((string) $key, '_driver')) {
                unset($line[$key]);
            }
        }

        return ['afterMilliseconds' => $afterMilliseconds, 'line' => $line, 'fromBroker' => $fromBroker];
    }

    /**
     * @return array<string, mixed>
     */
    private function runnerLine(string $name, string $event): array
    {
        foreach ($this->fixture($name) as $line) {
            if (($line['type'] ?? null) === 'runner' && ($line['event'] ?? null) === $event) {
                return $line;
            }
        }

        throw new RuntimeException("The fixture {$name}.jsonl has no runner {$event} line.");
    }

    /**
     * The fixture's lines after the header, read once per instance.
     *
     * @return list<array<string, mixed>>
     */
    private function fixture(string $name): array
    {
        if (isset($this->fixtures[$name])) {
            return $this->fixtures[$name];
        }

        $path = "{$this->fixtureDirectory}/{$name}.jsonl";
        $size = @filesize($path);

        if ($size === false) {
            throw new RuntimeException("The fixture {$name}.jsonl is missing.");
        }

        if ($size > self::MAX_FIXTURE_BYTES) {
            throw new RuntimeException("The fixture {$name}.jsonl is over " . self::MAX_FIXTURE_BYTES . ' bytes.');
        }

        $rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        if (count($rows) > self::MAX_FIXTURE_LINES) {
            throw new RuntimeException("The fixture {$name}.jsonl has more than " . self::MAX_FIXTURE_LINES . ' lines.');
        }

        array_shift($rows);
        $lines = [];

        foreach ($rows as $row) {
            $line = json_decode($row, true);

            if (! is_array($line)) {
                throw new RuntimeException("The fixture {$name}.jsonl has a line that is not JSON.");
            }

            if (isset($line['_driver_sent'])) {
                continue;
            }

            $lines[] = $line;
        }

        return $this->fixtures[$name] = $lines;
    }
}
