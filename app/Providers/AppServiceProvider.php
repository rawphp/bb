<?php

namespace App\Providers;

use App\Domain\Account\EmailAddress;
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
        // The sign-in code and reset routes send mail: three per route, address and IP in ten minutes.
        // No count is shared across IPs, so a stranger cannot use up the owner's sends. The inbox is
        // kept from floods by one code a minute (SendSignInCode) and the broker's one link a minute.
        RateLimiter::for('auth-mail', fn (Request $request) => Limit::perMinutes(10, 3)->by(
            $request->route()?->getName().'|'.EmailAddress::normalise((string) $request->input('email')).'|'.$request->ip(),
        ));

        // The reset link opens the PWA, which posts the token to /reset-password.
        ResetPassword::createUrlUsing(fn (object $user, string $token) => config('app.url').'/reset-password?'
            .http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }
}
