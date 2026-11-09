<?php

namespace App\Enums;

enum TaskMode: string
{
    case Fix = 'fix';
    case Research = 'research';
    case Setup = 'setup';
    case Review = 'review';

    /** The name shown to people; the stored value of Fix stays 'fix'. */
    public function label(): string
    {
        return match ($this) {
            self::Fix => 'Change',
            self::Research => 'Research',
            self::Setup => 'Setup',
            self::Review => 'Review',
        };
    }
}
