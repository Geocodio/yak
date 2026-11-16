<?php

namespace App\Enums;

enum TeleportState: string
{
    case Open = 'open';
    case WaitingForTurn = 'waiting_for_turn';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled, self::Expired], true);
    }

    /**
     * @return list<self>
     */
    public static function nonTerminal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $state): bool => ! $state->isTerminal()));
    }
}
