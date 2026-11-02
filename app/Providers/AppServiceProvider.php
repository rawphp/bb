<?php

namespace App\Providers;

use App\Domain\Account\EmailAddress;
use App\Queue\DatabaseConnector;
use App\Queue\RedisConnector;
use App\Support\RequestClock;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The queues that stamp absolute times keep real time while the clock is walked (SET-011).
        $this->app->extend('queue', function (QueueManager $queue, $app): QueueManager {
            $queue->addConnector('database', fn () => new DatabaseConnector($app['db']));
            $queue->addConnector('redis', fn () => new RedisConnector($app['redis']));

            return $queue;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RequestClock::carryIntoQueue();

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
