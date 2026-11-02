<?php

use App\Http\Controllers\AppShellController;
use App\Http\Controllers\Auth\CodeSignInController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SendSignInCodeController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SignOutController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Host routes are for signing in, the Stripe webhook and the app shell only (CAP-004).
// Every read and write goes through POST /capabilities/{name}.
Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::post('/register', RegisterController::class)->name('register');
    Route::post('/sign-in', SignInController::class)->name('sign-in');
    Route::post('/sign-in/code/verify', CodeSignInController::class)->name('sign-in.code.verify');
    Route::post('/reset-password', ResetPasswordController::class)->name('password.update');

    // These two send mail, so each address has its own limit (AppServiceProvider).
    Route::middleware('throttle:auth-mail')->group(function (): void {
        Route::post('/sign-in/code', SendSignInCodeController::class)->name('sign-in.code');
        Route::post('/forgot-password', ForgotPasswordController::class)->name('password.email');
    });
});

Route::post('/sign-out', SignOutController::class)->middleware('auth')->name('sign-out');

// Stripe's webhook, with billing on only (SUB-020, SUB-021). Stripe signs each
// request, so it takes no session, cookie or CSRF token from the web group.
if (config('billing.enabled')) {
    Route::post('/stripe/webhook', StripeWebhookController::class)->withoutMiddleware('web')->name('billing.webhook');
}

// The Vue app shell answers every other GET, so the client router owns the path.
// Capability, Sanctum, MCP and API paths are left to their own routes and 404s.
Route::get('/', AppShellController::class);
Route::fallback(AppShellController::class)->where('fallbackPlaceholder', '^(?!(capabilities|sanctum|mcp|api)(/|$)).*');
