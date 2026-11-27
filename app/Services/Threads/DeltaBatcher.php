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

    /** @var array<string, true> thread|blockKey => the block got its final entry; later deltas for it are dropped */
    private array $finishedBlocks = [];

    /** @var array<int, string> */
    private array $currentMessages = [];

    /** @var array<int, array<string, int>> messageId => index of the block started and not yet stopped */
    private array $openBlocks = [];

    /** @var array<string, float> thread|blockKey => time of the block's first delta */
    private array $firstDeltaTimes = [];

    /** @var array<string, int> thread|blockKey => UTF-16 code units already emitted */
    private array $offsets = [];

    /** @var array<string, array{messageId: string, blockIndex: int, kind: string, text: string, startedAt: float}> thread|blockKey => the block's full streamed text */
    private array $fullTexts = [];

    /** @var array<int, string> */
    private array $lastAssistantBlockKeys = [];

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
        $keys = array_merge(array_keys($this->fullTexts), array_keys($this->buffers), array_keys($this->firstDeltaTimes), array_keys($this->offsets));

        $keys = array_merge($keys, array_keys($this->finishedBlocks));

        return isset($this->openBlocks[$threadId]) || isset($this->currentMessages[$threadId]) || isset($this->lastAssistantBlockKeys[$threadId])
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

        return ['blockIndex' => $index, 'startedAt' => $startedAt === null ? null : $this->isoTime($startedAt)];
    }

    public function hasPending(): bool
    {
        return array_filter($this->buffers, fn (array $buffer): bool => $buffer['text'] !== '') !== [];
    }

    /**
     * Buffers a delta. A delta for a block whose final entry already went out is dropped: the
     * runner can release a held-back tail after the block's assistant line, and the entry
     * already holds that text.
     */
    public function add(int $threadId, string $messageId, int $index, string $kind, string $text, float $now): void
    {
        $blockKey = $messageId . ':' . $index;
        $key = $threadId . '|' . $blockKey;

        if (isset($this->finishedBlocks[$key])) {
            return;
        }

        $this->firstDeltaTimes[$key] ??= $now;
        $this->fullTexts[$key] ??= ['messageId' => $messageId, 'blockIndex' => $index, 'kind' => $kind, 'text' => '', 'startedAt' => $now];
        $this->fullTexts[$key]['text'] .= $text;

        if (isset($this->buffers[$key])) {
            $this->buffers[$key]['text'] .= $text;

            return;
        }

        $this->buffers[$key] = ['threadId' => $threadId, 'blockKey' => $blockKey, 'kind' => $kind, 'text' => $text, 'startedAt' => $now];
    }

    /**
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string, offset: int, startedAt?: string}>
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
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string, offset: int, startedAt?: string}>
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
     * The thread's blocks that streamed text but never got an assistant line, in stream order.
     * Tool input is never returned.
     *
     * @return list<array{messageId: string, blockIndex: int, kind: string, text: string, startedAt: string}>
     */
    public function takeUnfinishedBlocks(int $threadId): array
    {
        $blocks = [];

        foreach ($this->fullTexts as $key => $block) {
            if (str_starts_with($key, $threadId . '|') && $block['text'] !== '' && in_array($block['kind'], ['text', 'thinking'], true)) {
                $blocks[] = [...$block, 'startedAt' => $this->isoTime($block['startedAt'])];
            }
        }

        return $blocks;
    }

    /**
     * Remembers the block of the thread's latest assistant entry, or forgets it when that entry
     * did not stream.
     */
    public function rememberAssistantBlock(int $threadId, ?string $blockKey): void
    {
        if ($blockKey === null) {
            unset($this->lastAssistantBlockKeys[$threadId]);

            return;
        }

        $this->lastAssistantBlockKeys[$threadId] = $blockKey;
    }

    public function lastAssistantBlockKey(int $threadId): ?string
    {
        return $this->lastAssistantBlockKeys[$threadId] ?? null;
    }

    /**
     * Whether a block of the thread streamed text and has neither its final entry nor a reset yet.
     */
    public function hasOpenBlock(int $threadId): bool
    {
        return $this->unfinishedKeys($threadId) !== [];
    }

    /**
     * Ends one block when its final entry goes out; deltas that arrive for it later are dropped
     * until the turn ends.
     */
    public function finishBlock(int $threadId, string $blockKey): void
    {
        $key = $threadId . '|' . $blockKey;
        $this->finishedBlocks[$key] = true;
        unset($this->buffers[$key], $this->firstDeltaTimes[$key], $this->offsets[$key], $this->fullTexts[$key]);
    }

    /**
     * Forgets every block of the thread that has no final entry, for a stream the runner restarted.
     */
    public function discardUnfinishedBlocks(int $threadId): void
    {
        foreach ($this->unfinishedKeys($threadId) as $key) {
            unset($this->buffers[$key], $this->firstDeltaTimes[$key], $this->offsets[$key], $this->fullTexts[$key]);
        }
    }

    /**
     * Drops all of the thread's stream state once its turn is over.
     */
    public function closeThread(int $threadId): void
    {
        unset($this->openBlocks[$threadId], $this->currentMessages[$threadId], $this->lastAssistantBlockKeys[$threadId]);

        foreach (array_keys($this->firstDeltaTimes + $this->offsets + $this->fullTexts + $this->finishedBlocks) as $key) {
            if (str_starts_with((string) $key, $threadId . '|')) {
                unset($this->firstDeltaTimes[$key], $this->offsets[$key], $this->fullTexts[$key], $this->finishedBlocks[$key]);
            }
        }

        foreach ($this->buffers as $key => $buffer) {
            if ($buffer['threadId'] === $threadId) {
                unset($this->buffers[$key]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function unfinishedKeys(int $threadId): array
    {
        return array_values(array_filter(array_keys($this->fullTexts), fn (string $key): bool => str_starts_with($key, $threadId . '|')));
    }

    private function isoTime(float $seconds): string
    {
        return CarbonImmutable::createFromTimestampMs((int) round($seconds * 1000))->toIso8601String();
    }

    /**
     * @param  array{threadId: int, blockKey: string, kind: string, text: string, startedAt: float}  $buffer
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string, offset: int, startedAt?: string}>
     */
    private function emit(array $buffer): array
    {
        $chunks = [];
        $text = $buffer['text'];

        while ($text !== '') {
            $chunk = mb_strcut($text, 0, self::MAX_CHUNK_BYTES);
            $offsetKey = $buffer['threadId'] . '|' . $buffer['blockKey'];
            $offset = $this->offsets[$offsetKey] ?? 0;
            $chunks[] = ['threadId' => $buffer['threadId'], 'blockKey' => $buffer['blockKey'], 'kind' => $buffer['kind'], 'text' => $chunk, 'offset' => $offset]
                + ($offset === 0 ? ['startedAt' => $this->isoTime($this->firstDeltaTimes[$offsetKey] ?? $buffer['startedAt'])] : []);
            $this->offsets[$offsetKey] = $offset + intdiv(strlen(mb_convert_encoding($chunk, 'UTF-16LE', 'UTF-8')), 2);
            $text = (string) substr($text, strlen($chunk));
        }

        return $chunks;
    }
}
