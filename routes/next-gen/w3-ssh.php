<?php

use App\Http\Controllers\Settings\SshKeyController;
use App\Http\Controllers\Threads\TerminalSessionController;
use Illuminate\Support\Facades\Route;

Route::get('settings/ssh-keys', [SshKeyController::class, 'index'])->name('settings.ssh-keys');
Route::post('settings/ssh-keys', [SshKeyController::class, 'store'])->name('settings.ssh-keys.store');
Route::post('settings/ssh-keys/refresh', [SshKeyController::class, 'refresh'])->name('settings.ssh-keys.refresh');
Route::delete('settings/ssh-keys/{key}', [SshKeyController::class, 'destroy'])->name('settings.ssh-keys.destroy');

Route::post('threads/{thread}/terminal-sessions', [TerminalSessionController::class, 'store'])
    ->name('threads.terminal-sessions.store')
    ->where('thread', '[0-9]+');
