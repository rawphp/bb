<?php

namespace Tests\Unit\Capabilities\Reads\Plan;

use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\WeighIn;
use App\Support\Plan\PlanSnapshot;
use Illuminate\Support\Collection;

/**
 * rp-clone's PlanScenario as unsaved models, so the Plan payloads run without a database.
 *
 * The plan starts Monday 2026-08-31 and reviews on Mondays, so plan week k starts 31 Aug + 7k days. Today is
 * Wednesday 14 October, in week 6 (from 12 October).
 *
 * - "cut": Fat loss, weeks 0 to 11 (to 23 November), losing 0.75% a week.
 * - "hold": Maintenance, weeks 12 to 15 (to 21 December).
 * - "gain": Muscle gain, weeks 16 to 27 (to 15 March 2027), gaining 0.375% a week.
 * - Meso 1 is 6 weeks from week 0, Meso 2 is 6 weeks from week 6 (the current one), Meso 3 is 4 weeks from week
 *   12. Their deload weeks are 5, 11 and 15.
 * - Daily weigh-ins from 31 August for 45 days, from 90 kg down 0.1 kg a day.
 */
final class PlanWorld
{
    public const CUT = '01JB8Q2V6N3XKZ4M5P7R9T1W2A';

    public const HOLD = '01JB8Q2V6N3XKZ4M5P7R9T1W2B';

    public const GAIN = '01JB8Q2V6N3XKZ4M5P7R9T1W2C';

    public const MESO1 = '01JB8Q3A1C2D3E4F5G6H7J8K9M';

    public const MESO2 = '01JB8Q3A1C2D3E4F5G6H7J8K9N';

    public const MESO3 = '01JB8Q3A1C2D3E4F5G6H7J8K9P';

    /**
     * @param  list<Phase>|null  $phases
     * @param  list<Mesocycle>|null  $mesocycles
     * @param  list<PlanWeek>  $weeks
     * @param  list<WeighIn>|null  $weighIns
     */
    public static function snapshot(
        string $today = '2026-10-14',
        ?array $phases = null,
        ?array $mesocycles = null,
        array $weeks = [],
        ?array $weighIns = null,
        bool $trainingOnly = false,
        string $planStart = '2026-08-31',
    ): PlanSnapshot {
        return new PlanSnapshot(
            today: PlanSnapshot::day($today),
            plan: (new Plan)->forceFill(['id' => 1, 'user_id' => 1, 'start_date' => $planStart, 'review_weekday' => 1]),
            profile: (new Profile)->forceFill(['user_id' => 1, 'experience' => 'intermediate', 'weight_unit' => 'kg', 'training_only' => $trainingOnly]),
            phases: new Collection($phases ?? self::phases()),
            mesocycles: new Collection($mesocycles ?? self::mesocycles()),
            planWeeks: new Collection($weeks),
            weighIns: new Collection($weighIns ?? self::weighIns()),
        );
    }

    /** @return list<Phase> */
    public static function phases(): array
    {
        return [
            self::phase(self::CUT, 'fatLoss', '2026-08-31', '2026-11-23', -0.75),
            self::phase(self::HOLD, 'maintenance', '2026-11-23', '2026-12-21', 0),
            self::phase(self::GAIN, 'muscleGain', '2026-12-21', '2027-03-15', 0.375),
        ];
    }

    /** @return list<Mesocycle> */
    public static function mesocycles(): array
    {
        return [
            self::mesocycle(self::MESO1, 'Meso 1', self::CUT, '2026-08-31', 6),
            self::mesocycle(self::MESO2, 'Meso 2', self::CUT, '2026-10-12', 6),
            self::mesocycle(self::MESO3, 'Meso 3', self::HOLD, '2026-11-23', 4),
        ];
    }

    public static function phase(string $id, string $type, string $start, ?string $end, float $rate, ?string $event = null): Phase
    {
        return (new Phase)->forceFill([
            'id' => $id, 'user_id' => 1, 'type' => $type, 'start_date' => $start, 'end_date' => $end,
            'target_rate_percent_per_week' => $rate, 'event_date' => $event,
        ]);
    }

    /** @param  array<string, ?string>  $priorities */
    public static function mesocycle(string $id, string $name, ?string $phase, string $start, int $weeks, array $priorities = [], bool $resensitization = false): Mesocycle
    {
        return (new Mesocycle)->forceFill([
            'id' => $id, 'user_id' => 1, 'phase_id' => $phase, 'name' => $name, 'weeks' => $weeks, 'start_date' => $start,
            'is_draft' => false, 'is_resensitization' => $resensitization, 'days' => [], 'muscle_priorities' => $priorities,
        ]);
    }

    public static function week(string $start, bool $deload = false, bool $dietBreak = false, bool $dietBreakOff = false): PlanWeek
    {
        return (new PlanWeek)->forceFill([
            'user_id' => 1, 'start_date' => $start, 'is_deload' => $deload, 'is_diet_break' => $dietBreak,
            'diet_break_off' => $dietBreakOff, 'is_away' => false,
        ]);
    }

    /** @return list<WeighIn> */
    public static function weighIns(string $from = '2026-08-31', int $days = 45, float $startKg = 90, float $perDayKg = -0.1): array
    {
        $weighIns = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $weighIns[] = (new WeighIn)->forceFill([
                'id' => $offset + 1, 'user_id' => 1, 'weight_kg' => $startKg + $perDayKg * $offset,
                'weighed_at' => PlanSnapshot::day($from)->modify("+{$offset} days")->setTime(7, 0)->format('Y-m-d H:i:s'),
            ]);
        }

        return $weighIns;
    }
}
