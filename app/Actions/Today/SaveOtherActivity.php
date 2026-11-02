<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\OtherActivitySaveInput;
use App\Domain\Model\DayType;
use App\Domain\Retarget\OtherActivity;
use App\Domain\Retarget\Retargeting;
use App\Models\OtherActivity as OtherActivityRow;
use App\Models\User;
use App\Support\Today\DayPlans;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SCH-032, SCH-038, NUT-003, NUT-005: a day's Other activity, sport or cardio logged for nutrition only, saved under
 * the client's id (CAP-019). Its energy at the intensity picked joins the day as carbs, the day type is the higher of
 * it and the session's, and no meal moves: peri-workout meals stay around the mesocycle session only. A day holds at
 * most one, which the same id edits. The row and the day are saved together or not at all.
 */
final class SaveOtherActivity
{
    public const NO_NAME = 'Give the activity a name.';

    public const NAME_LENGTH = 100;

    public const LONG_NAME = 'Keep the name to 100 characters.';

    public const LENGTH = 'An activity lasts from 1 minute to 24 hours.';

    public const ONE_A_DAY = 'This day already has an Other activity. Edit that one instead.';

    public const STAYS = 'An Other activity stays on its day. Delete it and add it on the other day.';

    /**
     * @param  ?OtherActivityRow  $existing  the user's row with the input's id, if it has one
     * @return array{toast: string, dates: list<string>, week: list<string>} the toast, the day (Y-m-d) and its plan week
     */
    public function __invoke(User $user, ?OtherActivityRow $existing, OtherActivitySaveInput $input): array
    {
        $name = trim($input->name);
        if ($name === '') {
            throw new DomainException(self::NO_NAME);
        }
        if (mb_strlen($name) > self::NAME_LENGTH) {
            throw new DomainException(self::LONG_NAME);
        }
        if ($input->minutes < 1 || $input->minutes > 1440) {
            throw new DomainException(self::LENGTH);
        }
        if ($existing !== null && DayPlans::day($existing->date)->format('Y-m-d') !== $input->date) {
            throw new DomainException(self::STAYS);
        }
        $day = RetargetableDay::load($user, $input->date);
        $stored = OtherActivityRow::query()->where('user_id', $user->id)->whereDate('date', $input->date)->first();
        if ($stored !== null && $stored->getKey() !== $existing?->getKey()) {
            throw new DomainException(self::ONE_A_DAY);
        }

        $result = Retargeting::otherActivity($day->day, new OtherActivity($name, $input->minutes, DayType::from($input->intensity)), $day->context);
        DB::transaction(function () use ($day, $result, $existing, $user, $input, $name) {
            $day->save($result->days[0]);
            ($existing ?? new OtherActivityRow)->forceFill([
                'id' => $existing?->getKey() ?? $input->id,
                'user_id' => $user->id,
                'date' => $input->date,
                'start_time' => $input->start,
                'minutes' => $input->minutes,
                'name' => $name,
                'intensity' => $input->intensity,
            ])->save();
        });

        return ['toast' => $result->message, 'dates' => [$day->date], 'week' => $day->weekDates()];
    }
}
