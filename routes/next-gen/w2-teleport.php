<?php

use App\Http\Controllers\Threads\ThreadTeleportRequestController;
use Illuminate\Support\Facades\Route;

Route::post('threads/{thread}/teleport-request', [ThreadTeleportRequestController::class, 'store'])
    ->name('threads.teleport-request.store')
    ->where('thread', '[0-9]+');
Route::delete('threads/{thread}/teleport-request', [ThreadTeleportRequestController::class, 'destroy'])
    ->name('threads.teleport-request.destroy')
    ->where('thread', '[0-9]+');
