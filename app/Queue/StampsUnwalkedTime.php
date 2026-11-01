<?php

namespace App\Queue;

use App\Support\RequestClock;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The queue keeps real time while the clock is walked (SET-011): every time it
 * stamps or compares (available_at, reserved_at, created_at, a release, a bulk
 * push) comes from the clock before the walk, so a normal worker finds the job.
 * A delay given as an instant is taken as that far after the walked now.
 */
trait StampsUnwalkedTime
{
    protected function currentTime(): int
    {
        return RequestClock::unwalked()->getTimestamp();
    }

    /** @param  DateTimeInterface|\DateInterval|int  $delay */
    protected function availableAt($delay = 0): int
    {
        $delay = $this->parseDateInterval($delay);

        return $delay instanceof DateTimeInterface
            ? $this->currentTime() + $delay->getTimestamp() - Carbon::now()->getTimestamp()
            : RequestClock::unwalked()->addSeconds($delay)->getTimestamp();
    }
}
