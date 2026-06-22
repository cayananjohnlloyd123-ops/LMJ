<?php

use Illuminate\Support\Facades\Route;
use App\Models\Modules;

Route::view('/', 'welcome');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';

Modules::query()
    ->whereNotNull('route')
    ->where('is_active', true)
    ->get()
    ->each(function (Modules $module) {
        $uri = str_replace('.', '/', $module->route);

        Route::view($uri, $module->route)
            ->middleware(['auth', 'verified', 'module.access'])
            ->name($module->route);
    });