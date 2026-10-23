<?php

namespace App\Support;

class PersonName
{
    /** The first word of a full name, or an empty string for a blank name. */
    public static function first(string $name): string
    {
        return explode(' ', trim($name))[0];
    }
}
