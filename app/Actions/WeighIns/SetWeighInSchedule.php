<?php

namespace App\Actions\WeighIns;

use App\Domain\WeighIns\WeighInSchedule;
use App\Models\User;
use App\Models\WeighInScheduleEntry;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WT-001, WT-007: replace the whole weigh-in schedule. Deleting an entry is sending the rest, and the entries must
 * still hold 2 different weekdays. The time only sets the reminder.
 */
final class SetWeighInSchedule
{
    /** @param  list<array{weekdays: list<int>, time: string}>  $entries */
    public function __invoke(User $user, array $entries): void
    {
        if (count(WeighInSchedule::days(array_column($entries, 'weekdays'))) < WeighInSchedule::MINIMUM_DAYS) {
            throw new DomainException(WeighInSchedule::TOO_FEW_DAYS);
        }

        DB::transaction(function () use ($user, $entries): void {
            WeighInScheduleEntry::query()->where('user_id', $user->id)->delete();
            foreach ($entries as $entry) {
                $weekdays = array_map('intval', $entry['weekdays']);
                sort($weekdays);
                $user->weighInScheduleEntries()->create(['weekdays' => $weekdays, 'time' => $entry['time']]);
            }
        });
    }
}
