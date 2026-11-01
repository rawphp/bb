<?php

use App\Http\Middleware\UseRequestClock;
use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Rawphp\CapabilitiesAi\Jobs\RunTurnJob;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 09:00:00');

    // A probe in the api group that reports the request's clock and queues work.
    Route::middleware('api')->post('/api/_clock_probe', function () {
        dispatch(fn () => Cache::put('job_now', now()->toIso8601ZuluString()))->onConnection('database');

        return ['now' => now()->toIso8601ZuluString()];
    });
});

afterEach(fn () => Carbon::setTestNow());

it('SET-011 puts UseRequestClock first on the capability route', function () {
    $router = app(Router::class);
    $route = $router->getRoutes()->match(request()->create('/capabilities/account_show', 'POST'));

    expect($router->gatherRouteMiddleware($route)[0] ?? null)->toBe(UseRequestClock::class);
});

it('SET-011 puts UseRequestClock first in the api group', function () {
    expect(app(Router::class)->getMiddlewareGroups()['api'][0] ?? null)->toBe(UseRequestClock::class);
});

it('SET-011 invokes a capability at X-BB-Now in testing', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/_clock_probe', [], ['X-BB-Now' => '2031-03-04T05:06:07.000Z'])
        ->assertJsonPath('now', '2031-03-04T05:06:07Z');

    expect(now()->toIso8601ZuluString())->toBe('2026-11-01T09:00:00Z');
});

it('SET-011 ignores X-BB-Now in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->postJson('/api/_clock_probe', [], ['X-BB-Now' => '2031-03-04T05:06:07.000Z'])
        ->assertJsonPath('now', '2026-11-01T09:00:00Z');
});

it('SET-011 runs work queued by a walked request at the request clock', function () {
    $this->postJson('/api/_clock_probe', [], ['X-BB-Now' => '2031-03-04T05:06:07.000Z'])->assertOk();

    // The worker runs on the unwalked clock and still finds the job.
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect(Cache::get('job_now'))->toBe('2031-03-04T05:06:07Z')
        ->and(now()->toIso8601ZuluString())->toBe('2026-11-01T09:00:00Z');
});

it('SET-011 keeps queue timing on the clock from before the walk', function () {
    Route::middleware('api')->post('/api/_delay_probe', function () {
        dispatch(fn () => null)->onConnection('database');
        dispatch(fn () => null)->onConnection('database')->delay(30);

        return ['now' => now()->toIso8601ZuluString()];
    });

    $this->postJson('/api/_delay_probe', [], ['X-BB-Now' => '2031-03-04T05:06:07.000Z'])
        ->assertJsonPath('now', '2031-03-04T05:06:07Z');

    $start = Carbon::parse('2026-11-01 09:00:00')->getTimestamp();
    expect(DB::table('jobs')->orderBy('id')->pluck('available_at')->all())->toBe([$start, $start + 30]);
});

it('SET-011 hands the clock back when walked work throws', function () {
    Route::middleware('api')->post('/api/_throw_probe', function () {
        dispatch(fn () => throw new RuntimeException('boom'))->onConnection('database');

        return [];
    });

    $this->postJson('/api/_throw_probe', [], ['X-BB-Now' => '2031-03-04T05:06:07.000Z'])->assertOk();
    dispatch(fn () => Cache::put('job_now', now()->toIso8601ZuluString()))->onConnection('database');

    // One worker runs both jobs: the throwing one at 2031, then the plain one.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1]);

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(Cache::get('job_now'))->toBe('2026-11-01T09:00:00Z')
        ->and(now()->toIso8601ZuluString())->toBe('2026-11-01T09:00:00Z');
});

it('SET-011 records no clock on work queued outside a walk', function () {
    // Carbon::setTestNow in beforeEach freezes time but does not walk it.
    dispatch(fn () => null)->onConnection('database');

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
    expect($payload)->not->toHaveKey('bb_now');
});

it('SET-011 records the request clock on a queued agent turn', function () {
    Route::middleware('api')->post('/api/_turn_probe', function () {
        $job = new RunTurnJob('01TURN');
        $job->connection = 'database';
        dispatch($job);

        return [];
    });

    $this->postJson('/api/_turn_probe', [], ['X-BB-Now' => '2031-03-04T05:06:07.000Z'])->assertOk();

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
    expect($payload['displayName'])->toBe(RunTurnJob::class)
        ->and($payload['bb_now'])->toBe('2031-03-04T05:06:07.000+00:00');
});

it('SET-011 records no clock on work queued in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->postJson('/api/_clock_probe', [], ['X-BB-Now' => '2031-03-04T05:06:07.000Z'])->assertOk();

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
    expect($payload)->not->toHaveKey('bb_now');
});
