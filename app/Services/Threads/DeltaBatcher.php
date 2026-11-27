<?php

namespace App\Services\Threads;

use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Singleton;

/**
 * Collects streaming text per block so many tiny deltas go out as one event.
 * A buffer is due 50 ms after its first delta; a structural line takes a whole
 * thread's buffers early so a delta never follows its block's final entry.
 * Emitted text is split into chunks of at most MAX_CHUNK_BYTES so each broadcast
 * stays under the 8 KB cap. A singleton so the driver process shares one buffer.
 */
#[Singleton]
class DeltaBatcher
{
    public const WINDOW_SECONDS = 0.050;

    public const MAX_CHUNK_BYTES = 6000;

    /** @var array<string, array{threadId: int, blockKey: string, kind: string, text: string, startedAt: float}> */
    private array $buffers = [];

    /** @var array<int, true> */
    private array $openThreads = [];

    /** @var array<int, string> */
    private array $currentMessages = [];

    /** @var array<int, array<string, int>> messageId => index of the block started and not yet stopped */
    private array $openBlocks = [];

    /** @var array<string, float> thread|blockKey => time of the block's first delta */
    private array $firstDeltaTimes = [];

    /** @var array<string, int> thread|blockKey => UTF-16 code units already emitted */
    private array $offsets = [];

    /**
     * Remembers the thread's latest message id, the fallback for a stream line that carries none.
     */
    public function startMessage(int $threadId, string $messageId): void
    {
        $this->currentMessages[$threadId] = $messageId;
    }

    /**
     * Whether any buffer, open block, message or offset is still held for the thread.
     */
    public function hasStateFor(int $threadId): bool
    {
        $prefix = $threadId . '|';
        $keys = array_merge(array_keys($this->buffers), array_keys($this->firstDeltaTimes), array_keys($this->offsets));

        return isset($this->openThreads[$threadId]) || isset($this->openBlocks[$threadId]) || isset($this->currentMessages[$threadId])
            || array_filter($keys, fn (int|string $key): bool => str_starts_with((string) $key, $prefix)) !== [];
    }

    public function currentMessageId(int $threadId): ?string
    {
        return $this->currentMessages[$threadId] ?? null;
    }

    public function startBlock(int $threadId, string $messageId, int $index): void
    {
        $this->openBlocks[$threadId][$messageId] = $index;
    }

    public function stopBlock(int $threadId, string $messageId, int $index): void
    {
        if (($this->openBlocks[$threadId][$messageId] ?? null) === $index) {
            unset($this->openBlocks[$threadId][$messageId]);
        }
    }

    /**
     * The block of the message that is started and not yet stopped, with the time of its first delta.
     * The block's assistant line arrives before its stop.
     *
     * @return array{blockIndex: int, startedAt: ?string}|null
     */
    public function takeOpenBlock(int $threadId, string $messageId): ?array
    {
        $index = $this->openBlocks[$threadId][$messageId] ?? null;

        if ($index === null) {
            return null;
        }

        $startedAt = $this->firstDeltaTimes[$threadId . '|' . $messageId . ':' . $index] ?? null;

        return [
            'blockIndex' => $index,
            'startedAt' => $startedAt === null ? null : CarbonImmutable::createFromTimestampMs((int) round($startedAt * 1000))->toIso8601String(),
        ];
    }

    public function hasPending(): bool
    {
        return array_filter($this->buffers, fn (array $buffer): bool => $buffer['text'] !== '') !== [];
    }

    public function add(int $threadId, string $messageId, int $index, string $kind, string $text, float $now): void
    {
        $this->openThreads[$threadId] = true;
        $blockKey = $messageId . ':' . $index;
        $key = $threadId . '|' . $blockKey;
        $this->firstDeltaTimes[$key] ??= $now;

        if (isset($this->buffers[$key])) {
            $this->buffers[$key]['text'] .= $text;

            return;
        }

        $this->buffers[$key] = ['threadId' => $threadId, 'blockKey' => $blockKey, 'kind' => $kind, 'text' => $text, 'startedAt' => $now];
    }

    /**
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string, offset: int}>
     */
    public function due(float $now): array
    {
        $due = [];

        foreach ($this->buffers as $key => $buffer) {
            if ($now - $buffer['startedAt'] >= self::WINDOW_SECONDS) {
                unset($this->buffers[$key]);
                array_push($due, ...$this->emit($buffer));
            }
        }

        return $due;
    }

    /**
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string, offset: int}>
     */
    public function takeThread(int $threadId): array
    {
        $taken = [];

        foreach ($this->buffers as $key => $buffer) {
            if ($buffer['threadId'] === $threadId) {
                unset($this->buffers[$key]);
                array_push($taken, ...$this->emit($buffer));
            }
        }

        return $taken;
    }

    /**
     * Whether a streaming block of the thread has started and no entry or reset has closed it.
     */
    public function hasOpenBlock(int $threadId): bool
    {
        return isset($this->openThreads[$threadId]);
    }

    /**
     * Drops the thread's buffers and open-block mark.
     */
    public function closeThread(int $threadId): void
    {
        unset($this->openThreads[$threadId], $this->openBlocks[$threadId], $this->currentMessages[$threadId]);

        foreach (array_keys($this->firstDeltaTimes + $this->offsets) as $key) {
            if (str_starts_with((string) $key, $threadId . '|')) {
                unset($this->firstDeltaTimes[$key], $this->offsets[$key]);
            }
        }

        foreach ($this->buffers as $key => $buffer) {
            if ($buffer['threadId'] === $threadId) {
                unset($this->buffers[$key]);
            }
        }
    }

    /**
     * @param  array{threadId: int, blockKey: string, kind: string, text: string, startedAt: float}  $buffer
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string, offset: int}>
     */
    private function emit(array $buffer): array
    {
        $chunks = [];
        $text = $buffer['text'];

        while ($text !== '') {
            $chunk = mb_strcut($text, 0, self::MAX_CHUNK_BYTES);
            $offsetKey = $buffer['threadId'] . '|' . $buffer['blockKey'];
            $offset = $this->offsets[$offsetKey] ?? 0;
            $chunks[] = ['threadId' => $buffer['threadId'], 'blockKey' => $buffer['blockKey'], 'kind' => $buffer['kind'], 'text' => $chunk, 'offset' => $offset];
            $this->offsets[$offsetKey] = $offset + intdiv(strlen(mb_convert_encoding($chunk, 'UTF-16LE', 'UTF-8')), 2);
            $text = (string) substr($text, strlen($chunk));
        }

        return $chunks;
    }
}
