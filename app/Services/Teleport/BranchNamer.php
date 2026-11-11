<?php

namespace App\Services\Teleport;

use Illuminate\Support\Str;

/**
 * Turns a title into the slug and the `yak/` branch a teleported thread works on.
 */
class BranchNamer
{
    private const int MAX_LENGTH = 50;

    public function slug(string $titleHint): string
    {
        $slug = trim(substr(Str::slug($titleHint), 0, self::MAX_LENGTH), '-');

        return $slug === '' ? 'thread' : $slug;
    }

    public function branchFor(string $slug): string
    {
        return 'yak/' . $slug;
    }
}
