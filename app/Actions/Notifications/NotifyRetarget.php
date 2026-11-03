<?php

namespace App\Actions\Notifications;

use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Notifications\ReminderKind;
use App\Domain\Notifications\ReminderPlanner;
use App\Jobs\SendPushNotification;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\NotificationPref;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\UserDay;

/**
 * NOTIF-006: after a re-target is saved, tells the user when it changed a meal they planned foods for. Sent after the
 * write commits, so a write that rolls back sends nothing.
 */
final class NotifyRetarget
{
    /** @param  array<int, array<string, mixed>>  $before  the day's stored meals before the re-target */
    public function __invoke(DayTarget $row, array $before): void
    {
        $user = $row->user;
        if ($user === null || ! NotificationPref::switches($user)->isOn(ReminderKind::Retarget) || ! $user->pushSubscriptions()->exists()) {
            return;
        }
        $planned = DayTargetMeal::query()->where('day_target_id', $row->id)
            ->where(fn ($query) => $query->whereHas('plannedFoods')->orWhereHas('plannedRecipes'))
            ->pluck('id')->map(fn (mixed $id): string => strtolower((string) $id))->all();

        $notice = ReminderPlanner::retargetNotice(self::meals($before), self::meals($row->meals ?? []), $planned,
            TrainSnapshot::day($row->date), UserDay::now($user));
        if ($notice !== null) {
            SendPushNotification::dispatch($user, $notice->title, $notice->body, $notice->url)->afterCommit();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $stored  as stored, keyed in order
     * @return list<Meal>
     */
    private static function meals(array $stored): array
    {
        $stored = array_values($stored);

        return array_map(fn (array $meal, int $position) => new Meal(
            strtolower((string) ($meal['id'] ?? '')),
            ($meal['name'] ?? null) ?: 'Meal '.($position + 1),
            new MealTarget((float) ($meal['kcal'] ?? 0), (float) ($meal['protein_g'] ?? 0), (float) ($meal['fat_g'] ?? 0), (float) ($meal['carbs_g'] ?? 0)),
        ), $stored, array_keys($stored));
    }
}
