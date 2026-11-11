<?php

use App\Http\Controllers\Threads\ContinueTurnController;
use App\Http\Controllers\Threads\RestoreRetryController;
use App\Http\Controllers\Threads\ThreadMessageController;
use Illuminate\Support\Facades\Route;

Route::post('threads/{thread}/messages', [ThreadMessageController::class, 'store'])
    ->name('threads.messages.store')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/stop', [ThreadMessageController::class, 'stop'])
    ->name('threads.stop')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/continue', ContinueTurnController::class)
    ->name('threads.continue')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/restore/retry', RestoreRetryController::class)
    ->name('threads.restore.retry')
    ->where('thread', '[0-9]+');
