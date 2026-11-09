<?php

namespace App\Services\Threads;

/**
 * Collects streaming text per block so many tiny deltas go out as one event.
 * A buffer is due 50 ms after its first delta; a structural line takes a whole
 * thread's buffers early so a delta never follows its block's final entry.
 */
class DeltaBatcher
{
    public const WINDOW_SECONDS = 0.050;

    /** @var array<string, array{threadId: int, blockKey: string, kind: string, text: string, startedAt: float}> */
    private array $buffers = [];

    public function add(int $threadId, string $blockKey, string $kind, string $text, float $now): void
    {
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
                $due[] = $this->emit($buffer);
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
                $taken[] = $this->emit($buffer);
            }
        }

        return $taken;
    }

    /**
     * @param  array{threadId: int, blockKey: string, kind: string, text: string, startedAt: float}  $buffer
     * @return array{threadId: int, blockKey: string, kind: string, text: string}
     */
    private function emit(array $buffer): array
    {
        return ['threadId' => $buffer['threadId'], 'blockKey' => $buffer['blockKey'], 'kind' => $buffer['kind'], 'text' => $buffer['text']];
    }
}
