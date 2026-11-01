<?php

namespace App\Providers;

use App\Queue\DatabaseConnector;
use App\Queue\RedisConnector;
use App\Support\RequestClock;
use Illuminate\Queue\QueueManager;
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
    }
}
