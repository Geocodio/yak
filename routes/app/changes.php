<?php

use App\Http\Controllers\Api\App\ObservationController;
use App\Http\Controllers\Api\App\Tasks\ClarificationAnswerController;
use App\Http\Controllers\Api\App\Tasks\CoOwnerDecisionController;
use App\Http\Controllers\Api\App\Tasks\EarlyWarningController;
use App\Http\Controllers\Api\App\Tasks\TaskActionController;
use App\Http\Controllers\Api\App\Tasks\TaskController;
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

Route::get('observations', ObservationController::class)->name('app.observations.index');

Route::get('tasks', TaskListController::class)->name('app.tasks.index');
Route::get('tasks/{task}', [TaskController::class, 'show'])->name('app.tasks.show');
Route::get('tasks/{task}/co-owner/candidates', [EarlyWarningController::class, 'candidates'])->name('app.tasks.co-owner.candidates');
Route::middleware('task.drive')->group(function (): void {
    Route::post('tasks/{task}/retry', [TaskActionController::class, 'retry'])->name('app.tasks.retry');
    Route::post('tasks/{task}/cancel', [TaskActionController::class, 'cancel'])->name('app.tasks.cancel');
    Route::post('tasks/{task}/rerun-review', [TaskActionController::class, 'rerunReview'])->name('app.tasks.rerun-review');
    Route::post('tasks/{task}/retry-render', [TaskActionController::class, 'retryRender'])->name('app.tasks.retry-render');
    Route::post('tasks/{task}/reroute', [TaskActionController::class, 'reroute'])->name('app.tasks.reroute');
    Route::post('tasks/{task}/co-owner/approve', [CoOwnerDecisionController::class, 'approve'])->name('app.tasks.co-owner.approve');
    Route::post('tasks/{task}/co-owner/request-changes', [CoOwnerDecisionController::class, 'requestChanges'])->name('app.tasks.co-owner.request-changes');
    Route::post('tasks/{task}/co-owner/reject', [CoOwnerDecisionController::class, 'reject'])->name('app.tasks.co-owner.reject');
    Route::post('tasks/{task}/co-owner/withdraw', [CoOwnerDecisionController::class, 'withdraw'])->name('app.tasks.co-owner.withdraw');
    Route::post('tasks/{task}/co-owner/tag', [EarlyWarningController::class, 'tag'])->name('app.tasks.co-owner.tag');
    Route::post('tasks/{task}/co-owner/decline', [EarlyWarningController::class, 'decline'])->name('app.tasks.co-owner.decline');
    Route::post('tasks/{task}/clarification-answers', ClarificationAnswerController::class)->name('app.tasks.clarification-answers.store');
});
