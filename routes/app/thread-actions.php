<?php

use App\Http\Controllers\Api\App\Threads\ThreadCoOwnerController;
use App\Http\Controllers\Api\App\Threads\ThreadRepositoryAnswerController;
use App\Http\Controllers\Api\App\Threads\ThreadTaskActionController;
use Illuminate\Support\Facades\Route;

Route::middleware('thread.drive')->group(function (): void {
    Route::get('threads/{thread}/co-owner/candidates', [ThreadCoOwnerController::class, 'candidates'])->name('app.threads.co-owner.candidates');
    Route::post('threads/{thread}/retry', [ThreadTaskActionController::class, 'retry'])->name('app.threads.retry');
    Route::post('threads/{thread}/setup-and-continue', [ThreadTaskActionController::class, 'setUpRepositoryAndContinue'])->name('app.threads.setup-and-continue');
    Route::post('threads/{thread}/cancel', [ThreadTaskActionController::class, 'cancel'])->name('app.threads.cancel');
    Route::post('threads/{thread}/move', [ThreadTaskActionController::class, 'move'])->name('app.threads.move');
    Route::post('threads/{thread}/request-review', [ThreadTaskActionController::class, 'requestReview'])->name('app.threads.request-review');
    Route::post('threads/{thread}/retry-render', [ThreadTaskActionController::class, 'retryRender'])->name('app.threads.retry-render');
    Route::post('threads/{thread}/repository-answer', ThreadRepositoryAnswerController::class)->name('app.threads.repository-answer');
    Route::post('threads/{thread}/co-owner/approve', [ThreadCoOwnerController::class, 'approve'])->name('app.threads.co-owner.approve');
    Route::post('threads/{thread}/co-owner/request-changes', [ThreadCoOwnerController::class, 'requestChanges'])->name('app.threads.co-owner.request-changes');
    Route::post('threads/{thread}/co-owner/reject', [ThreadCoOwnerController::class, 'reject'])->name('app.threads.co-owner.reject');
    Route::post('threads/{thread}/co-owner/withdraw', [ThreadCoOwnerController::class, 'withdraw'])->name('app.threads.co-owner.withdraw');
    Route::post('threads/{thread}/co-owner/check-access', [ThreadCoOwnerController::class, 'checkAccess'])->name('app.threads.co-owner.check-access');
    Route::post('threads/{thread}/co-owner/tag', [ThreadCoOwnerController::class, 'tag'])->name('app.threads.co-owner.tag');
    Route::post('threads/{thread}/co-owner/decline', [ThreadCoOwnerController::class, 'decline'])->name('app.threads.co-owner.decline');
});
