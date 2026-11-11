<?php

use App\Http\Controllers\Internal\SshActivityController;
use App\Http\Controllers\Internal\SshAuthorizedKeysController;
use App\Http\Controllers\Internal\SshListenersController;
use App\Http\Controllers\Internal\SshWakeController;
use Illuminate\Support\Facades\Route;

Route::middleware('ssh-internal-token')
    ->prefix('/internal/ssh')
    ->name('internal.ssh.')
    ->group(function (): void {
        Route::post('authorized-keys', SshAuthorizedKeysController::class)->name('authorized-keys');
        Route::get('listeners', SshListenersController::class)->name('listeners');
        Route::post('wake', SshWakeController::class)->name('wake');
        Route::post('activity', SshActivityController::class)->name('activity');
    });
