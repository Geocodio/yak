<?php

namespace App\Enums;

enum PushTokenKind: string
{
    case Alert = 'alert';
    case LiveActivityStart = 'live_activity_start';
    case Widget = 'widget';

    /**
     * The route constraint that accepts every kind.
     */
    public static function routePattern(): string
    {
        return implode('|', array_column(self::cases(), 'value'));
    }
}
