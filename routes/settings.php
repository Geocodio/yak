<?php

use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\ClaudeAccountController;
use App\Http\Controllers\Settings\GitHubAccountController;
use App\Http\Controllers\Settings\LinearConnectionController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\VideoThemeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/profile/direct-messages', [ProfileController::class, 'updateDirectMessages'])->name('profile.direct-messages.update');
    Route::post('settings/profile/resend-verification', [ProfileController::class, 'resendVerification'])->name('verification.resend');

    Route::get('settings/github/connect', [GitHubAccountController::class, 'connect'])->name('settings.github.connect');
    Route::get('settings/github/callback', [GitHubAccountController::class, 'callback'])->name('settings.github.callback');
    Route::delete('settings/github', [GitHubAccountController::class, 'disconnect'])->name('settings.github.disconnect');

    Route::delete('settings/account', [AccountController::class, 'destroy'])->name('account.destroy');

    Route::get('settings/linear', [LinearConnectionController::class, 'edit'])->name('settings.linear');
    Route::patch('settings/linear', [LinearConnectionController::class, 'update'])->name('settings.linear.update');
    Route::delete('settings/linear', [LinearConnectionController::class, 'disconnect'])->name('settings.linear.disconnect');

    Route::get('settings/claude-accounts', [ClaudeAccountController::class, 'index'])->name('settings.claude-accounts');
    Route::post('settings/claude-accounts', [ClaudeAccountController::class, 'store'])->name('settings.claude-accounts.store');
    Route::put('settings/claude-accounts/{account}/secret', [ClaudeAccountController::class, 'rotate'])->name('settings.claude-accounts.rotate');
    Route::patch('settings/claude-accounts/{account}', [ClaudeAccountController::class, 'update'])->name('settings.claude-accounts.update');
    Route::delete('settings/claude-accounts/{account}', [ClaudeAccountController::class, 'destroy'])->name('settings.claude-accounts.destroy');

    Route::get('settings/video', [VideoThemeController::class, 'edit'])->name('settings.video');
    Route::put('settings/video', [VideoThemeController::class, 'update'])->name('settings.video.update');
    Route::post('settings/video/reset', [VideoThemeController::class, 'reset'])->name('settings.video.reset');
    Route::delete('settings/video/logo', [VideoThemeController::class, 'destroyLogo'])->name('settings.video.logo.destroy');
    Route::post('settings/video/sample', [VideoThemeController::class, 'sample'])->name('settings.video.sample');
});
