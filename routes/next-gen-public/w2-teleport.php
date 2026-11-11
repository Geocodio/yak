<?php

use App\Http\Controllers\Api\Cli\SessionFileController;
use App\Http\Controllers\Api\Cli\ThreadLookupController;
use App\Http\Middleware\CliProtocol;
use Illuminate\Support\Facades\Route;

Route::prefix('api/cli/v1')
    ->middleware(['cli.protocol', 'auth.device'])
    ->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)
    ->group(function (): void {
        Route::get('threads/by-session/{sessionId}', [ThreadLookupController::class, 'bySession']);
        Route::get('threads/{thread}', [ThreadLookupController::class, 'show'])->where('thread', '[0-9]+');

        Route::prefix('threads/{thread}/session')
            ->where(['thread' => '[0-9]+'])
            ->middleware('throttle:cli-session')
            ->group(function (): void {
                Route::get('length', [SessionFileController::class, 'length']);
                Route::post('append', [SessionFileController::class, 'append']);
                Route::post('checkpoint', [SessionFileController::class, 'checkpoint']);
                Route::put('file', [SessionFileController::class, 'file']);
                Route::get('files', [SessionFileController::class, 'files']);
                Route::get('read', [SessionFileController::class, 'read']);
                Route::get('tree', [SessionFileController::class, 'tree']);
            });
    });
