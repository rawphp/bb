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

// The Vue app shell answers every other GET, so the client router owns the path.
// Capability, Sanctum and MCP paths are left to their own routes and 404s.
// The dev screens and gallery exist in local and testing only.
$shell = function (?string $path = null) {
    $devRoutes = app()->environment('local', 'testing');

    abort_if(! $devRoutes && preg_match('~^dev(/|$)~', (string) $path) === 1, 404);

    return view('app', ['devRoutes' => $devRoutes]);
};

Route::get('/', $shell);
Route::fallback($shell)->where('fallbackPlaceholder', '^(?!(capabilities|sanctum|mcp|api)(/|$)).*');
