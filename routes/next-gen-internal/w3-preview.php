<?php

use App\Http\Controllers\Internal\ThreadPreviewWakeController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/threads/preview/wake', ThreadPreviewWakeController::class)->name('threads.preview.wake');
