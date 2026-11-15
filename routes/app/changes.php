<?php

use App\Http\Controllers\Api\App\Tasks\TaskListController;
use App\Http\Controllers\Api\App\Threads\PullRequestController;
use App\Http\Controllers\Api\App\Threads\ThreadDiffController;
use Illuminate\Support\Facades\Route;

Route::get('threads/{thread}/diff', [ThreadDiffController::class, 'show'])->name('app.threads.diff.show');
Route::get('threads/{thread}/diff/files/{file}', [ThreadDiffController::class, 'showFile'])->middleware('throttle:app-events')->where('file', '[0-9]{1,10}')->name('app.threads.diff.files.show');
Route::post('threads/{thread}/pull-request', [PullRequestController::class, 'store'])->name('app.threads.pull-request.store');
Route::get('threads/{thread}/pull-requests', [PullRequestController::class, 'index'])->name('app.threads.pull-requests.show');
Route::put('threads/{thread}/pull-requests/order', [PullRequestController::class, 'order'])->name('app.threads.pull-requests.order');
Route::post('threads/{thread}/pull-requests/{repository}/fix', [PullRequestController::class, 'fix'])->middleware('thread.drive')->where('repository', '[0-9]{1,10}')->name('app.threads.pull-requests.fix');

Route::get('tasks', TaskListController::class)->name('app.tasks.index');
