<?php

use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SignInController;
use Illuminate\Support\Facades\Route;

// Host routes are for signing in and the app shell only (CAP-004).
// Every read and write goes through POST /capabilities/{name}.
Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::post('/register', RegisterController::class)->name('register');
    Route::post('/sign-in', SignInController::class)->name('sign-in');
});
