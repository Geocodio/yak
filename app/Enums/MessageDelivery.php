<?php

namespace App\Enums;

/**
 * How a message sent to a running thread reaches the agent.
 */
enum MessageDelivery: string
{
    case Steer = 'steer';
    case AfterTurn = 'after_turn';
}
