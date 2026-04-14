<?php

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\LinearConnection;
use App\Livewire\Settings\Profile;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
    Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');
    Route::livewire('settings/linear', LinearConnection::class)->name('settings.linear');
});
