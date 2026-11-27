<?php

namespace App\Listeners;

use App\Enums\TurnMessageKind;
use App\Events\ThreadActivity;
use App\Models\ThreadAgent;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\BrokerLine;
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

        $isTurnEnd = ($line['type'] ?? null) === 'result' || BrokerLine::event($line) === 'runner_exited';

        if ($isTurnEnd) {
            $this->publisher->appendDeltas($this->batcher->takeThread($threadId));
        }

        if ($this->isInterruptMarker($line) && ($blockKey = $this->batcher->lastAssistantBlockKey($threadId)) !== null) {
            $this->publisher->append($threadId, 'block.cut', ['blockKey' => $blockKey], isStored: true);
        }

        $projected = $this->projector->project($line, $this->batcher->hasOpenBlock($threadId), ThreadAgent::isEnabled());

        if ($projected === []) {
            if ($isTurnEnd) {
                $this->closeStream($threadId);
            }

            return;
        }

        $this->publisher->appendDeltas($this->batcher->takeThread($threadId));

        foreach ($projected as $item) {
            if (($item['data']['role'] ?? null) === 'assistant' && is_string($item['data']['messageId'] ?? null) && ($block = $this->batcher->takeOpenBlock($threadId, $item['data']['messageId'])) !== null && $block['startedAt'] !== null) {
                $item['data'] = [...$item['data'], 'blockIndex' => $block['blockIndex'], 'startedAt' => $block['startedAt']];
            }

            if ($item['type'] === 'stream.reset') {
                $this->storeUnfinishedBlocks($threadId);
            }

            if ($item['type'] === 'stream.reset' || ($item['data']['role'] ?? null) === 'assistant') {
                $this->batcher->closeThread($threadId);
            }

            if (isset($item['data']['blockIndex']) && is_string($item['data']['messageId'] ?? null)) {
                $this->batcher->rememberAssistantBlock($threadId, $item['data']['messageId'] . ':' . $item['data']['blockIndex']);
            }

            $this->publisher->append($threadId, $item['type'], $this->withPromptKind($item), isStored: $item['type'] !== 'stream.reset');
        }

        if ($isTurnEnd) {
            $this->closeStream($threadId);
        }
    }

    /**
     * Stores the text of every block that streamed but never got its assistant line, then ends
     * the thread's stream bookkeeping once its turn is over.
     */
    private function closeStream(int $threadId): void
    {
        $this->storeUnfinishedBlocks($threadId);
        $this->batcher->closeThread($threadId);
    }

    private function storeUnfinishedBlocks(int $threadId): void
    {
        foreach ($this->batcher->takeUnfinishedBlocks($threadId) as $block) {
            $this->publisher->append($threadId, 'entry', $this->projector->withHtml([
                'role' => 'assistant',
                'messageId' => $block['messageId'],
                'blockIndex' => $block['blockIndex'],
                'startedAt' => $block['startedAt'],
                'cutOff' => true,
                'blocks' => [['type' => $block['kind'], 'text' => $block['text']]],
            ]), isStored: true);
        }
    }

    /**
     * Claude Code writes this user line after the partial assistant line of an interrupted reply.
     *
     * @param  array<string, mixed>  $line
     */
    private function isInterruptMarker(array $line): bool
    {
        if (($line['type'] ?? null) !== 'user' || ($line['isSynthetic'] ?? false) === true) {
            return false;
        }

        $content = $line['message']['content'] ?? null;
        $text = is_string($content) ? $content : (is_array($content) ? (string) ($content[0]['text'] ?? '') : '');

        return str_starts_with(trim($text), '[Request interrupted by user');
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
