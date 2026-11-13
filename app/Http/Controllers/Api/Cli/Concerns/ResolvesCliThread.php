<?php

namespace App\Http\Controllers\Api\Cli\Concerns;

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Responses\CliError;
use App\Models\Device;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

trait ResolvesCliThread
{
    protected function cliDevice(Request $request): Device
    {
        return AuthenticateDevice::device($request);
    }

    /**
     * A missing thread and one the caller may not see answer identically.
     */
    protected function cliThread(Request $request, string $threadId): Thread
    {
        $thread = Thread::withoutGlobalScopes()
            ->where(ctype_digit($threadId) ? 'id' : 'public_id', $threadId)
            ->first();

        return $this->visibleCliThread($request, $thread);
    }

    protected function visibleCliThread(Request $request, ?Thread $thread): Thread
    {
        $device = $this->cliDevice($request);

        if ($thread === null || ! app(ThreadAccess::class)->canSee($device->user, $thread)) {
            throw new HttpResponseException(CliError::make('thread_not_found', 'That thread does not exist.', 404));
        }

        return $thread;
    }
}
