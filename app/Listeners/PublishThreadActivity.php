<?php

namespace App\Listeners;

use App\Enums\TurnMessageKind;
use App\Events\ThreadActivity;
use App\Models\ThreadAgent;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\DeltaBatcher;
use App\Services\Threads\ThreadEventProjector;
use App\Services\Threads\ThreadEventPublisher;

/**
 * Turns one handled, already redacted runner line into sequenced thread events. Streaming
 * deltas are buffered; a structural line flushes the thread's buffer first, so a delta never
 * follows its block's final entry. Every line also flushes the batches that are due, which
 * stands in for a driver-side timer.
 */
class PublishThreadActivity
{
    public function __construct(
        private readonly ThreadEventProjector $projector,
        private readonly DeltaBatcher $batcher,
        private readonly ThreadEventPublisher $publisher,
    ) {}

    public function handle(ThreadActivity $event): void
    {
        $this->publisher->flushDueDeltas();

        $threadId = $event->thread->id;
        $line = $event->line;

        if (($line['type'] ?? null) === 'stream_event') {
            $this->handleStreamLine($threadId, $line);

            return;
        }

        $projected = $this->projector->project($line, $this->batcher->hasOpenBlock($threadId), ThreadAgent::isEnabled());

        if ($projected === []) {
            return;
        }

        $this->publisher->appendDeltas($this->batcher->takeThread($threadId));

        foreach ($projected as $item) {
            if (($item['data']['role'] ?? null) === 'assistant' && is_string($item['data']['messageId'] ?? null) && ($block = $this->batcher->takeOpenBlock($threadId, $item['data']['messageId'])) !== null) {
                $item['data'] = [...$item['data'], 'blockIndex' => $block['blockIndex'], 'startedAt' => $block['startedAt']];
            }

            if ($item['type'] === 'stream.reset' || ($item['data']['role'] ?? null) === 'assistant') {
                $this->batcher->closeThread($threadId);
            }

            $this->publisher->append($threadId, $item['type'], $this->withPromptKind($item), isStored: $item['type'] !== 'stream.reset');
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function handleStreamLine(int $threadId, array $line): void
    {
        $streamEvent = $this->projector->streamEvent($line);

        if ($streamEvent === null) {
            return;
        }

        if ($streamEvent['type'] === 'message_start' && $streamEvent['messageId'] !== null) {
            $this->batcher->startMessage($threadId, $streamEvent['messageId']);

            return;
        }

        $messageId = $streamEvent['messageId'] ?? $this->batcher->currentMessageId($threadId);

        if ($messageId === null) {
            return;
        }

        match ($streamEvent['type']) {
            'content_block_start' => $this->batcher->startBlock($threadId, $messageId, $streamEvent['index']),
            'content_block_stop' => $this->batcher->stopBlock($threadId, $messageId, $streamEvent['index']),
            'content_block_delta' => $this->addDelta($threadId, $messageId, $line),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function addDelta(int $threadId, string $messageId, array $line): void
    {
        if (($delta = $this->projector->delta($line)) !== null) {
            $this->batcher->add($threadId, $messageId, $delta['index'], $delta['kind'], $delta['text'], now()->getTimestampMs() / 1000);
        }
    }

    /**
     * A user entry is a steer when its uuid was sent as one.
     *
     * @param  array{type: string, data: array<string, mixed>}  $item
     * @return array<string, mixed>
     */
    private function withPromptKind(array $item): array
    {
        $data = $item['data'];

        if (($data['role'] ?? null) === 'user' && ThreadTurnMessage::query()->where('uuid', $data['uuid'])->where('kind', TurnMessageKind::Steer)->exists()) {
            $data['kind'] = 'steer';
        }

        return $data;
    }
}
