<?php

use App\Http\Controllers\Threads\ThreadDiffController;
use App\Http\Controllers\Threads\ThreadEnvironmentController;
use App\Http\Controllers\Threads\ThreadPullRequestController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/diff', [ThreadDiffController::class, 'show'])
    ->name('threads.diff.show')
    ->where('thread', '[0-9]+');
Route::get('threads/{thread}/diff/files/{file}', [ThreadDiffController::class, 'showFile'])
    ->name('threads.diff.files.show')
    ->where(['thread' => '[0-9]+', 'file' => '[0-9]+']);
Route::get('threads/{thread}/environment', [ThreadEnvironmentController::class, 'show'])
    ->name('threads.environment.show')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/pull-request', [ThreadPullRequestController::class, 'store'])
    ->name('threads.pull-request.store')
    ->where('thread', '[0-9]+');
