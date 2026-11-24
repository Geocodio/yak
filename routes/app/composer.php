<?php

use App\Http\Controllers\Api\App\Threads\ThreadAgentController;
use App\Http\Controllers\Api\App\Threads\ThreadStepLimitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['thread.drive', 'throttle:app-composer'])->group(function (): void {
    Route::post('threads/{thread}/step-limit', ThreadStepLimitController::class)->name('app.threads.step-limit.store');
    Route::post('threads/{thread}/agents/stop', [ThreadAgentController::class, 'stopAll'])->name('app.threads.agents.stop');
});
