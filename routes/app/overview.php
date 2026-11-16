<?php

use App\Http\Controllers\Api\App\Threads\StackController;
use App\Http\Controllers\Api\App\Threads\ThreadEnvironmentController;
use App\Http\Controllers\Api\App\Threads\ThreadRepositoryController;
use Illuminate\Support\Facades\Route;

// Overview and sub-threads.
Route::get('threads/{thread}/stack', [StackController::class, 'show'])->name('app.threads.stack.show');
Route::get('threads/{thread}/environment', [ThreadEnvironmentController::class, 'show'])->name('app.threads.environment.show');
Route::post('threads/{thread}/stack/children', [StackController::class, 'storeChild'])->name('app.threads.stack.children.store');
Route::post('threads/{thread}/stack/stop', [StackController::class, 'stop'])->name('app.threads.stack.stop');
Route::patch('threads/{thread}/stack/limit', [StackController::class, 'limit'])->name('app.threads.stack.limit');
Route::post('threads/{thread}/stack/take-over', [StackController::class, 'takeOver'])->name('app.threads.stack.take-over');
Route::post('threads/{thread}/stack/split', [StackController::class, 'split'])->name('app.threads.stack.split');
Route::post('threads/{thread}/stack/drop', [StackController::class, 'drop'])->name('app.threads.stack.drop');
Route::post('threads/{thread}/repositories/{repository}/retry', [ThreadRepositoryController::class, 'retry'])->middleware('thread.drive')->where('repository', '[0-9]+')->scopeBindings()->name('app.threads.repositories.retry');
