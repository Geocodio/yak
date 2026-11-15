<?php

use App\Http\Controllers\Api\App\Threads\Preview\PreviewHeartbeatController;
use App\Http\Controllers\Api\App\Threads\Preview\PreviewStatusController;
use App\Http\Controllers\Api\App\Threads\Preview\PreviewTicketController;
use App\Http\Controllers\Api\App\Threads\Preview\PreviewWarmController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/preview/status', PreviewStatusController::class)->name('app.threads.preview.status');
Route::post('threads/{thread}/preview/warm', PreviewWarmController::class)->name('app.threads.preview.warm');
Route::post('threads/{thread}/preview/heartbeat', PreviewHeartbeatController::class)->name('app.threads.preview.heartbeat');
Route::post('threads/{thread}/preview/ticket', PreviewTicketController::class)->name('app.threads.preview.ticket');
