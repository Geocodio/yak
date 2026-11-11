<?php

use App\Http\Controllers\Repositories\RepositoryClaudeAccountController;
use App\Http\Controllers\Settings\ClaudeAccountController;
use Illuminate\Support\Facades\Route;

Route::get('settings/claude-accounts', [ClaudeAccountController::class, 'index'])->name('settings.claude-accounts');
Route::post('settings/claude-accounts', [ClaudeAccountController::class, 'store'])->name('settings.claude-accounts.store');
Route::post('settings/claude-accounts/{account}/rotate', [ClaudeAccountController::class, 'rotate'])->name('settings.claude-accounts.rotate');
Route::patch('settings/claude-accounts/{account}', [ClaudeAccountController::class, 'update'])->name('settings.claude-accounts.update');
Route::delete('settings/claude-accounts/{account}', [ClaudeAccountController::class, 'destroy'])->name('settings.claude-accounts.destroy');

Route::put('repos/{repository}/claude-account', [RepositoryClaudeAccountController::class, 'updateMine'])->name('repos.claude-account.update');
Route::put('repos/{repository}/unattended-claude-account', [RepositoryClaudeAccountController::class, 'updateUnattended'])->name('repos.unattended-claude-account.update');
