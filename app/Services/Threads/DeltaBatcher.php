<?php

namespace App\Services\Threads;

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

    public function add(int $threadId, string $blockKey, string $kind, string $text, float $now): void
    {
        $this->openThreads[$threadId] = true;
        $key = $threadId . '|' . $blockKey;

        if (isset($this->buffers[$key])) {
            $this->buffers[$key]['text'] .= $text;

            return;
        }

        $this->buffers[$key] = ['threadId' => $threadId, 'blockKey' => $blockKey, 'kind' => $kind, 'text' => $text, 'startedAt' => $now];
    }

    /**
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string}>
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
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string}>
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
        unset($this->openThreads[$threadId]);

        foreach ($this->buffers as $key => $buffer) {
            if ($buffer['threadId'] === $threadId) {
                unset($this->buffers[$key]);
            }
        }
    }

    /**
     * @param  array{threadId: int, blockKey: string, kind: string, text: string, startedAt: float}  $buffer
     * @return list<array{threadId: int, blockKey: string, kind: string, text: string}>
     */
    private function emit(array $buffer): array
    {
        $chunks = [];
        $text = $buffer['text'];

        while ($text !== '') {
            $chunk = mb_strcut($text, 0, self::MAX_CHUNK_BYTES);
            $chunks[] = ['threadId' => $buffer['threadId'], 'blockKey' => $buffer['blockKey'], 'kind' => $buffer['kind'], 'text' => $chunk];
            $text = (string) substr($text, strlen($chunk));
        }

        return $chunks;
    }
}
