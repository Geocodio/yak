<?php

use App\Http\Controllers\Devices\DeviceApprovalController;
use App\Http\Controllers\Settings\DeviceController as SettingsDeviceController;
use Illuminate\Support\Facades\Route;

Route::get('device', [DeviceApprovalController::class, 'show'])->name('device.show');
Route::post('device/approve', [DeviceApprovalController::class, 'approve'])->name('device.approve');
Route::post('device/deny', [DeviceApprovalController::class, 'deny'])->name('device.deny');

Route::get('settings/devices', [SettingsDeviceController::class, 'index'])->name('settings.devices');
Route::delete('settings/devices/{device}', [SettingsDeviceController::class, 'destroy'])->name('settings.devices.destroy');
