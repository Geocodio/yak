<?php

use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\LinearConnectionController;
use App\Http\Controllers\Settings\McpLoginController;
use App\Http\Controllers\Settings\McpServerController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\VideoThemeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/resend-verification', [ProfileController::class, 'resendVerification'])->name('verification.resend');

    Route::delete('settings/account', [AccountController::class, 'destroy'])->name('account.destroy');

    Route::get('settings/linear', [LinearConnectionController::class, 'edit'])->name('settings.linear');
    Route::patch('settings/linear', [LinearConnectionController::class, 'update'])->name('settings.linear.update');
    Route::delete('settings/linear', [LinearConnectionController::class, 'disconnect'])->name('settings.linear.disconnect');

    Route::get('settings/video', [VideoThemeController::class, 'edit'])->name('settings.video');
    Route::put('settings/video', [VideoThemeController::class, 'update'])->name('settings.video.update');
    Route::post('settings/video/reset', [VideoThemeController::class, 'reset'])->name('settings.video.reset');
    Route::delete('settings/video/logo', [VideoThemeController::class, 'destroyLogo'])->name('settings.video.logo.destroy');
    Route::post('settings/video/sample', [VideoThemeController::class, 'sample'])->name('settings.video.sample');

    Route::get('settings/mcp', [McpServerController::class, 'index'])->name('settings.mcp');
    Route::post('settings/mcp', [McpServerController::class, 'store'])->name('settings.mcp.store');

    // Registered before the shorter settings.mcp.destroy/logout routes
    // below: {name} is deliberately greedy (`.+`) so a colon-bearing
    // plugin name like "plugin:figma:figma" is captured whole, but that
    // greediness also lets it swallow a trailing "/login" or
    // "/login/redirect" segment. Laravel matches routes in registration
    // order, so the longer URIs must come first or every one of these
    // would be shadowed by settings.mcp.destroy/settings.mcp.logout.
    Route::post('settings/mcp/{name}/login/redirect', [McpLoginController::class, 'redirect'])->where('name', '.+')->name('settings.mcp.login.redirect');
    Route::post('settings/mcp/{name}/login', [McpLoginController::class, 'start'])->where('name', '.+')->name('settings.mcp.login.start');
    Route::delete('settings/mcp/{name}/login', [McpLoginController::class, 'cancel'])->where('name', '.+')->name('settings.mcp.login.cancel');

    Route::post('settings/mcp/{name}/logout', [McpServerController::class, 'logout'])->where('name', '.+')->name('settings.mcp.logout');
    Route::delete('settings/mcp/{name}', [McpServerController::class, 'destroy'])->where('name', '.+')->name('settings.mcp.destroy');
});
