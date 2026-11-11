<?php

use App\Http\Controllers\Internal\ThreadPreviewHostStatusController;
use App\Http\Controllers\Internal\ThreadPreviewWakeController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/threads/preview/wake', ThreadPreviewWakeController::class)->name('threads.preview.wake');
Route::get('/internal/threads/preview/status', ThreadPreviewHostStatusController::class)->name('threads.preview.host-status');
