<?php

namespace App\Http\Requests\Concerns;

use App\Services\Threads\ThreadPublishGuard;

/**
 * For Form Requests that open a pull request from a thread.
 */
trait AcknowledgesPrivatePublish
{
    public function acknowledgedPrivatePublish(): bool
    {
        return $this->boolean(ThreadPublishGuard::ACKNOWLEDGEMENT_KEY);
    }
}
