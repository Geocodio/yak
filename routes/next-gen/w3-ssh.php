<?php

use App\Http\Controllers\Settings\SshKeyController;
use Illuminate\Support\Facades\Route;

Route::get('settings/ssh-keys', [SshKeyController::class, 'index'])->name('settings.ssh-keys');
Route::post('settings/ssh-keys', [SshKeyController::class, 'store'])->name('settings.ssh-keys.store');
Route::post('settings/ssh-keys/refresh', [SshKeyController::class, 'refresh'])->name('settings.ssh-keys.refresh');
Route::delete('settings/ssh-keys/{key}', [SshKeyController::class, 'destroy'])->name('settings.ssh-keys.destroy');
