<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The sign-in code and reset routes send mail: three per address in ten minutes.
        RateLimiter::for('auth-mail', fn (Request $request) => Limit::perMinutes(10, 3)
            ->by('auth-mail|'.mb_strtolower((string) $request->input('email'))));

        // The reset link opens the PWA, which posts the token to /reset-password.
        ResetPassword::createUrlUsing(fn (object $user, string $token) => config('app.url').'/reset-password?'
            .http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }
}
