<?php

use App\Http\Controllers\Api\App\Threads\Preview\PreviewStatusController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/preview/status', PreviewStatusController::class)->name('app.threads.preview.status');
