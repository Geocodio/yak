<?php

use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\ClaudeAccountController;
use App\Http\Controllers\Settings\DeviceController as SettingsDeviceController;
use App\Http\Controllers\Settings\EmailAddressController;
use App\Http\Controllers\Settings\GitHubAccountController;
use App\Http\Controllers\Settings\LinearConnectionController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\PushDeviceController;
use App\Http\Controllers\Settings\SshKeyController;
use App\Http\Controllers\Settings\VideoThemeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/profile/direct-messages', [ProfileController::class, 'updateDirectMessages'])->name('profile.direct-messages.update');
    Route::post('settings/profile/resend-verification', [ProfileController::class, 'resendVerification'])->name('verification.resend');

    Route::post('settings/profile/email-addresses', [EmailAddressController::class, 'store'])->name('profile.email-addresses.store');
    Route::post('settings/profile/email-addresses/{emailAddress}/resend', [EmailAddressController::class, 'resend'])->name('profile.email-addresses.resend');
    Route::delete('settings/profile/email-addresses/{emailAddress}', [EmailAddressController::class, 'destroy'])->name('profile.email-addresses.destroy');
    Route::get('email-addresses/{emailAddress}/confirm', [EmailAddressController::class, 'show'])->middleware('signed')->name('email-addresses.confirm.show');
    Route::post('email-addresses/{emailAddress}/confirm', [EmailAddressController::class, 'confirm'])->middleware('signed')->name('email-addresses.confirm');

    Route::get('settings/github/connect', [GitHubAccountController::class, 'connect'])->name('settings.github.connect');
    Route::get('settings/github/callback', [GitHubAccountController::class, 'callback'])->name('settings.github.callback');
    Route::delete('settings/github', [GitHubAccountController::class, 'disconnect'])->name('settings.github.disconnect');

    Route::delete('settings/account', [AccountController::class, 'destroy'])->name('account.destroy');

    Route::get('settings/linear', [LinearConnectionController::class, 'edit'])->name('settings.linear');
    Route::patch('settings/linear', [LinearConnectionController::class, 'update'])->name('settings.linear.update');
    Route::delete('settings/linear', [LinearConnectionController::class, 'disconnect'])->name('settings.linear.disconnect');

    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('settings.notifications');
    Route::put('settings/notifications', [NotificationController::class, 'updatePreferences'])->name('settings.notifications.preferences');
    Route::post('settings/notifications/devices', [PushDeviceController::class, 'store'])->name('settings.notifications.devices.store');
    Route::patch('settings/notifications/devices/{pushDevice}', [PushDeviceController::class, 'update'])->name('settings.notifications.devices.update');
    Route::delete('settings/notifications/devices/{pushDevice}', [PushDeviceController::class, 'destroy'])->name('settings.notifications.devices.destroy');
    Route::post('settings/notifications/devices/{pushDevice}/test', [PushDeviceController::class, 'test'])->name('settings.notifications.devices.test');

    Route::get('settings/video', [VideoThemeController::class, 'edit'])->name('settings.video');
    Route::put('settings/video', [VideoThemeController::class, 'update'])->name('settings.video.update');
    Route::post('settings/video/reset', [VideoThemeController::class, 'reset'])->name('settings.video.reset');
    Route::delete('settings/video/logo', [VideoThemeController::class, 'destroyLogo'])->name('settings.video.logo.destroy');
    Route::post('settings/video/sample', [VideoThemeController::class, 'sample'])->name('settings.video.sample');

    // Claude accounts, devices and ssh keys.
    Route::get('settings/claude-accounts', [ClaudeAccountController::class, 'index'])->name('settings.claude-accounts');
    Route::post('settings/claude-accounts', [ClaudeAccountController::class, 'store'])->name('settings.claude-accounts.store');
    Route::post('settings/claude-accounts/{account}/rotate', [ClaudeAccountController::class, 'rotate'])->name('settings.claude-accounts.rotate');
    Route::patch('settings/claude-accounts/{account}', [ClaudeAccountController::class, 'update'])->name('settings.claude-accounts.update');
    Route::delete('settings/claude-accounts/{account}', [ClaudeAccountController::class, 'destroy'])->name('settings.claude-accounts.destroy');
    Route::get('settings/devices', [SettingsDeviceController::class, 'index'])->name('settings.devices');
    Route::delete('settings/devices/{device}', [SettingsDeviceController::class, 'destroy'])->name('settings.devices.destroy');
    Route::get('settings/ssh-keys', [SshKeyController::class, 'index'])->name('settings.ssh-keys');
    Route::post('settings/ssh-keys', [SshKeyController::class, 'store'])->name('settings.ssh-keys.store');
    Route::post('settings/ssh-keys/refresh', [SshKeyController::class, 'refresh'])->name('settings.ssh-keys.refresh');
    Route::delete('settings/ssh-keys/{key}', [SshKeyController::class, 'destroy'])->name('settings.ssh-keys.destroy');
});
