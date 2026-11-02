<?php

namespace App\Domain\Progress;

use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Phase;
use DateTimeImmutable;
use DateTimeZone;

final class MesocycleHistory
{
    /**
     * Past mesocycles end (start plus weeks x 7 days) on or before today, newest first.
     *
     * Average weekly sets per muscle = logged working sets of that muscle in the mesocycle's sessions, divided
     * by the number of distinct weeks with any logged set in it, rounded to one decimal. Every set type
     * (regular, myo-rep, myo-rep match) is a working set; warm-ups are not stored.
     *
     * @param  list<ProgressMesocycle>  $mesocycles
     * @param  list<Phase>  $phases
     * @param  list<LoggedWorkingSet>  $sets
     * @return list<MesocycleHistoryEntry>
     */
    public static function entries(
        array $mesocycles, array $phases, array $sets, DateTimeImmutable $today, DateTimeZone $zone,
    ): array {
        $todayStart = Days::startOf($today, $zone);
        $past = array_values(array_filter($mesocycles, fn (ProgressMesocycle $meso) => $meso->end($zone) <= $todayStart));
        usort($past, fn (ProgressMesocycle $a, ProgressMesocycle $b) => $b->start <=> $a->start);

        return array_map(function (ProgressMesocycle $meso) use ($phases, $sets, $zone) {
            $weeks = [];
            $counts = [];
            foreach ($sets as $set) {
                if ($set->mesocycleId === $meso->id) {
                    $weeks[$set->week] = true;
                    $counts[$set->muscle->value] = ($counts[$set->muscle->value] ?? 0) + 1;
                }
            }
            $weekCount = count($weeks);
            $weekly = [];
            foreach (MuscleGroup::cases() as $muscle) {
                $count = $counts[$muscle->value] ?? null;
                if ($count !== null && $weekCount > 0) {
                    $weekly[] = new MuscleWeeklySets($muscle, round($count / $weekCount * 10) / 10);
                }
            }
            $phaseType = null;
            foreach ($phases as $phase) {
                if ($phase->id === $meso->phaseId) {
                    $phaseType = $phase->type;
                    break;
                }
            }

            return new MesocycleHistoryEntry(
                id: $meso->id, name: $meso->name, start: Days::startOf($meso->start, $zone), end: $meso->end($zone),
                phaseType: $phaseType, weeksWithSets: $weekCount, weeklySets: $weekly,
            );
        }, $past);
    }
}
