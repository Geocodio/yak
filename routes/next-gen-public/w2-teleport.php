<?php

use App\Http\Controllers\Api\Cli\ThreadLookupController;
use App\Http\Middleware\CliProtocol;
use Illuminate\Support\Facades\Route;

Route::prefix('api/cli/v1')
    ->middleware(['cli.protocol', 'auth.device'])
    ->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)
    ->group(function (): void {
        Route::get('threads/by-session/{sessionId}', [ThreadLookupController::class, 'bySession']);
        Route::get('threads/{thread}', [ThreadLookupController::class, 'show'])->where('thread', '[0-9]+');
    });
