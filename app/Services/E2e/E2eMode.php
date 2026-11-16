<?php

namespace App\Services\E2e;

final class E2eMode
{
    /** True only in the testing environment with `yak.e2e.enabled` true. Never in local, staging or production. */
    public static function isActive(): bool
    {
        return app()->environment('testing') && config('yak.e2e.enabled') === true;
    }
}
