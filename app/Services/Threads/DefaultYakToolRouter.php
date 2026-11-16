<?php

namespace App\Services\Threads;

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Models\Thread;

class DefaultYakToolRouter implements YakToolRouter
{
    public function handle(Thread $thread, YakToolCall $call): YakToolResult
    {
        return new YakToolResult(true, 'The ' . $call->tool . ' tool is not available yet.');
    }
}
