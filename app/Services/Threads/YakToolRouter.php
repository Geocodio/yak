<?php

namespace App\Services\Threads;

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Models\Thread;
use Illuminate\Container\Attributes\Bind;

/**
 * Runs the Yak tools Claude calls from a thread's runner. Stacks and
 * multi-repo threads replace the binding or decorate it.
 */
#[Bind(DefaultYakToolRouter::class)]
interface YakToolRouter
{
    public function handle(Thread $thread, YakToolCall $call): YakToolResult;
}
