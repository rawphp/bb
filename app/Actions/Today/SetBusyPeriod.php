<?php

namespace App\Actions\Today;

use App\Actions\Settings\SaveProfile;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\BusyPeriodSetInput;
use App\Models\Profile;
use App\Models\User;
use App\Support\Today\TodaySnapshot;

/**
 * SCH-033: the schedule menu's Add busy period. The period is saved on the profile's `busy_periods`, the list the
 * re-target rule places meals around (RetargetDays::context()), under the client's ULID, so saving the same id
 * again edits it. The save is a settings save (SET-004): the days after today are rebuilt with it.
 */
final class SetBusyPeriod
{
    public const NO_LABEL = 'Give the busy period a name.';

    public const NO_WEEKDAYS = 'Pick at least one day it repeats on.';

    public const NO_LENGTH = 'The busy period must end at a different time from its start.';

    public function __construct(private readonly SaveProfile $save) {}

    /** @return list<string> the dates the rebuild wrote, Y-m-d */
    public function __invoke(User $user, BusyPeriodSetInput $input): array
    {
        $label = trim($input->label);
        if ($label === '') {
            throw new FieldRefused('label', self::NO_LABEL);
        }
        if ($input->weekdays === []) {
            throw new FieldRefused('weekdays', self::NO_WEEKDAYS);
        }
        if ($input->start === $input->end) {
            throw new FieldRefused('end', self::NO_LENGTH);
        }
        $weekdays = array_values(array_unique($input->weekdays));
        sort($weekdays);
        $id = strtolower($input->id);
        $period = ['id' => $id, 'label' => $label, 'start' => $input->start, 'end' => $input->end, 'weekdays' => $weekdays];

        // The merge reads the profile SaveProfile has locked, so a period saved meanwhile elsewhere is kept.
        return ($this->save)($user, [], function (Profile $profile) use ($period): void {
            $profile->fill(['busy_periods' => self::upserted(TodaySnapshot::json($profile->busy_periods), $period)]);
        });
    }

    /**
     * The list with `$period` in place of the one with its id, else added last.
     *
     * @param  array<array-key, mixed>  $periods
     * @param  array{id: string}  $period
     * @return list<mixed>
     */
    private static function upserted(array $periods, array $period): array
    {
        $periods = array_values($periods);
        foreach ($periods as $index => $stored) {
            if (is_array($stored) && strtolower((string) ($stored['id'] ?? '')) === $period['id']) {
                $periods[$index] = $period;

                return $periods;
            }
        }

        return [...$periods, $period];
    }
}
