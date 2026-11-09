<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\ThreadMessages;
use Illuminate\Http\JsonResponse;

class ThreadMessageController extends Controller
{
    public function index(Thread $thread): JsonResponse
    {
        return response()->json(['messages' => ThreadMessages::for($thread)]);
    }
}
