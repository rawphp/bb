<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The time-walk clock (SET-011, architecture.md "Walking through time").
 *
 * In local and testing, a request may carry X-BB-Now and runs at that instant.
 * A job queued while the clock is walked records the instant and runs at it too,
 * so an agent turn sees the same "today" as the request that started it.
 * Every other environment ignores the header and the recorded instant.
 *
 * The queue itself keeps the clock from before the walk; see App\Queue\StampsUnwalkedTime.
 */
final class RequestClock
{
    public const HEADER = 'X-BB-Now';

    public const PAYLOAD_KEY = 'bb_now';

    /** @var list<CarbonInterface|Closure|null> clocks to restore, innermost last */
    private static array $previous = [];

    public static function honoured(): bool
    {
        return app()->environment('local', 'testing');
    }

    /** Null when the value is missing, unparseable or not honoured here. */
    public static function parse(?string $value): ?CarbonImmutable
    {
        if (! self::honoured() || $value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public static function apply(CarbonImmutable $at): void
    {
        self::$previous[] = Carbon::getTestNow();
        Carbon::setTestNow($at);
    }

    public static function release(): void
    {
        if (self::$previous !== []) {
            Carbon::setTestNow(array_pop(self::$previous));
        }
    }

    public static function walking(): bool
    {
        return self::$previous !== [];
    }

    /** The clock from before the outermost walk. */
    public static function unwalked(): CarbonImmutable
    {
        return self::walking()
            ? Carbon::withTestNow(self::$previous[0], fn () => CarbonImmutable::now())
            : CarbonImmutable::now();
    }

    /**
     * Records the walked clock in every queued payload and applies it while the job runs.
     */
    public static function carryIntoQueue(): void
    {
        self::recordInPayloads();

        Event::listen(function (JobProcessing $event): void {
            $at = self::parse($event->job->payload()[self::PAYLOAD_KEY] ?? null);
            if ($at !== null) {
                self::apply($at);
            }
        });

        $done = function (JobProcessed|JobExceptionOccurred $event): void {
            if (self::parse($event->job->payload()[self::PAYLOAD_KEY] ?? null) !== null) {
                self::release();
            }
        };
        Event::listen(JobProcessed::class, $done);
        Event::listen(JobExceptionOccurred::class, $done);
    }

    /** A static queue hook: Laravel's test teardown clears it, so Tests\TestCase adds it back. */
    public static function recordInPayloads(): void
    {
        Queue::createPayloadUsing(fn (): array => self::honoured() && self::walking()
            ? [self::PAYLOAD_KEY => Carbon::now()->format(DATE_RFC3339_EXTENDED)]
            : []);
    }
}
