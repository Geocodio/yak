<?php

use App\Http\Controllers\Devices\DeviceApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('device', [DeviceApprovalController::class, 'show'])->name('device.show');
Route::post('device/approve', [DeviceApprovalController::class, 'approve'])->name('device.approve');
Route::post('device/deny', [DeviceApprovalController::class, 'deny'])->name('device.deny');
