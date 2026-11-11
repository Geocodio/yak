<?php

use App\Http\Controllers\Internal\SessionStoreController;
use Illuminate\Support\Facades\Route;

Route::middleware('session-store-token')
    ->prefix('/internal/session-store/{thread}')
    ->where(['thread' => '[0-9]+'])
    ->name('session-store.')
    ->group(function (): void {
        Route::post('append', [SessionStoreController::class, 'append'])->name('append');
        Route::get('length', [SessionStoreController::class, 'length'])->name('length');
        Route::post('checkpoint', [SessionStoreController::class, 'checkpoint'])->name('checkpoint');
        Route::put('file', [SessionStoreController::class, 'file'])->name('file');
        Route::get('read', [SessionStoreController::class, 'read'])->name('read');
        Route::get('files', [SessionStoreController::class, 'files'])->name('files');
        Route::get('tree', [SessionStoreController::class, 'tree'])->name('tree');
    });
