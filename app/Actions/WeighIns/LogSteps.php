<?php

namespace App\Actions\WeighIns;

use App\Domain\WeighIns\StepCounts;
use App\Enums\MeasurementSource;
use App\Models\Step;
use App\Models\User;
use App\Support\WeighIns\ReadingTime;

/**
 * WT-020: set a day's step count. One count per day; whether a new one replaces the day's is StepCounts::replaces:
 * the bridge never overwrites a typed count. A replay sets the same count again (OFF-030).
 */
final class LogSteps
{
    /** @return bool whether the day's count is now this one */
    public function __invoke(User $user, string $date, int $steps, MeasurementSource $source): bool
    {
        $date = ReadingTime::day($date);
        $day = Step::query()->where('user_id', $user->id)->whereDate('date', $date)->first();
        if ($day !== null && ! StepCounts::replaces($day->source, $source)) {
            return false;
        }

        ($day ?? (new Step)->forceFill(['user_id' => $user->id, 'date' => $date]))
            ->forceFill(['count' => $steps, 'source' => $source])
            ->save();

        return true;
    }
}
