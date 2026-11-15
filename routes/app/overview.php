<?php

use App\Http\Controllers\Api\App\Threads\StackController;
use App\Http\Controllers\Api\App\Threads\ThreadEnvironmentController;
use Illuminate\Support\Facades\Route;

// Overview and sub-threads.
Route::get('threads/{thread}/stack', [StackController::class, 'show'])->name('app.threads.stack.show');
Route::get('threads/{thread}/environment', [ThreadEnvironmentController::class, 'show'])->name('app.threads.environment.show');
Route::post('threads/{thread}/stack/children', [StackController::class, 'storeChild'])->name('app.threads.stack.children.store');
