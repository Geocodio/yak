<?php

namespace App\DataTransferObjects;

use App\Enums\MessageDelivery;
use App\Models\PendingSteeringMessage;
use App\Models\ThreadTurnMessage;
use Closure;

final readonly class ThreadMessageInput
{
    /**
     * @param  (Closure(ThreadTurnMessage|PendingSteeringMessage): mixed)|null  $storeAttachments  Receives the ThreadTurnMessage or PendingSteeringMessage the message became.
     */
    public function __construct(
        public string $text,
        public ?string $model,
        public ?string $intent,
        public ?string $sendKey,
        public MessageDelivery $delivery,
        public ?Closure $storeAttachments,
        public bool $acknowledgedPrivatePublish = false,
        public ?string $effort = null,
        public bool $queueWithoutRepository = false,
        public bool $hasAttachments = false,
    ) {}
}
