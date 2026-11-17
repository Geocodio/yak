<?php

namespace App\Enums;

enum EffortLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    /**
     * Every level, lowest first.
     *
     * @return list<string>
     */
    public static function ascending(): array
    {
        return array_map(fn (self $level): string => $level->value, self::cases());
    }
}
