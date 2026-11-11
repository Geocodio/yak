<?php

use App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController;
use App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController;
use App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController;
use App\Http\Controllers\Threads\Preview\ThreadPreviewWarmController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/preview/status', ThreadPreviewStatusController::class)
    ->name('threads.preview.status')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/preview/heartbeat', ThreadPreviewHeartbeatController::class)
    ->name('threads.preview.heartbeat')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/preview/warm', ThreadPreviewWarmController::class)
    ->name('threads.preview.warm')
    ->where('thread', '[0-9]+');
Route::post('threads/{thread}/preview/restore', ThreadPreviewRestoreController::class)
    ->name('threads.preview.restore')
    ->where('thread', '[0-9]+');
