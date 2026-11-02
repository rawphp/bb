<?php

namespace App\Actions\Today;

use App\Domain\Retarget\Retargeting;
use App\Models\OtherActivity as OtherActivityRow;
use App\Models\User;
use App\Support\Today\DayPlans;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** SCH-032: a day's Other activity deleted, and its energy taken back out of the day. Its id stays spent (CAP-019). */
final class DeleteOtherActivity
{
    /**
     * @return array{toast: string, dates: list<string>, week: list<string>} the toast, the day (Y-m-d) and its plan week
     *
     * @throws ModelNotFoundException<OtherActivityRow> for an id the user does not hold
     */
    public function __invoke(User $user, string $id): array
    {
        $row = OtherActivityRow::query()->where('user_id', $user->id)->findOrFail($id);
        $day = RetargetableDay::load($user, DayPlans::day($row->date)->format('Y-m-d'));
        $result = Retargeting::otherActivity($day->day, null, $day->context);
        DB::transaction(function () use ($day, $result, $row) {
            $day->save($result->days[0]);
            $row->delete();
        });

        return ['toast' => $result->message, 'dates' => [$day->date], 'week' => $day->weekDates()];
    }
}
