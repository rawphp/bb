<?php

namespace App\Domain\Placement;

/**
 * The search behind MealPlacement. Works in minutes from the window's start ("offsets"), so a window that crosses
 * midnight is one straight line from 0 to its length.
 *
 * @internal
 */
final class MealPlacementSolver
{
    private readonly ClockTime $origin;

    /** @var list<array{int, int}> Half-open [start, end) offsets no app-placed meal may take. */
    private readonly array $blocked;

    /** @var array{lo: int, hi: int, target: int}|null */
    private readonly ?array $pre;

    /** @var array{lo: int, hi: int, target: int}|null */
    private readonly ?array $post;

    /** @var list<int> Indexes of the meals with no fixed time, in order. */
    private readonly array $free;

    private readonly int $regulars;

    /** @var list<MealRole> The peri-workout roles in time order; each is a fixed meal or one to place. */
    private readonly array $roles;

    private readonly bool $placePre;

    private readonly bool $placePost;

    /** @var list<int> Each node's offset, in time order. */
    private readonly array $nodeAt;

    /** @var list<array{meal: int, role: MealRole}|null> Set for a fixed meal: its index and its role. */
    private readonly array $nodeFixed;

    /**
     * @param  list<MealSlot>  $meals
     * @param  list<ClockRange>  $busy
     * @param  list<MealRole>  $peri  The peri-workout meals this attempt may place.
     */
    public function __construct(
        private readonly array $meals,
        ClockRange $window,
        array $busy,
        ?WorkoutTime $workout,
        array $peri,
    ) {
        $day = ClockTime::MINUTES_PER_DAY;
        $this->origin = $window->start;
        $length = $window->lengthInMinutes();

        $blocked = [];
        foreach ($busy as $period) {
            $start = $this->origin->minutesUntil($period->start);
            $end = $start + $period->lengthInMinutes();
            $blocked[] = [$start, $end];
            $blocked[] = [$start - $day, $end - $day];
        }
        $session = null;
        if ($workout !== null) {
            $start = $window->offset($workout->start);
            $end = $start + $workout->lengthMinutes;
            $session = [$start, $end];
            $blocked[] = [$start, $end];
            $this->pre = [
                'lo' => $start - MealPlacement::PRE_WORKOUT_MINUTES_BEFORE_MAX,
                'hi' => $start - MealPlacement::PRE_WORKOUT_MINUTES_BEFORE_MIN,
                'target' => $start - MealPlacement::PRE_WORKOUT_TARGET_MINUTES_BEFORE,
            ];
            $this->post = [
                'lo' => $end + MealPlacement::POST_WORKOUT_MINUTES_AFTER_MIN,
                'hi' => $end + MealPlacement::POST_WORKOUT_MINUTES_AFTER_MAX,
                'target' => $end + MealPlacement::POST_WORKOUT_MINUTES_AFTER_MIN,
            ];
        } else {
            $this->pre = null;
            $this->post = null;
        }
        $this->blocked = $blocked;

        // A fixed meal in the 3 hours before the session is its pre-workout meal, and one from the session's start to
        // an hour after it is its post-workout meal, so no other meal ever sits between the two.
        $fixed = [];
        foreach ($meals as $i => $meal) {
            if ($meal->fixedTime !== null) {
                $fixed[] = ['meal' => $i, 'at' => $window->offset($meal->fixedTime)];
            }
        }
        $preFixed = null;
        $postFixed = null;
        if ($session !== null) {
            foreach ($fixed as $anchor) {
                $at = $anchor['at'];
                if ($at >= $session[0] - MealPlacement::PRE_WORKOUT_MINUTES_BEFORE_MAX && $at < $session[0]
                    && ($preFixed === null || $at > $preFixed['at'])) {
                    $preFixed = $anchor;
                }
                if ($at >= $session[0] && $at <= $session[1] + MealPlacement::POST_WORKOUT_MINUTES_AFTER_MAX
                    && ($postFixed === null || $at < $postFixed['at'])) {
                    $postFixed = $anchor;
                }
            }
        }

        $grid = MealPlacement::GRID_MINUTES;
        $marks = [0, $length];
        for ($at = ($grid - $this->origin->minuteOfDay % $grid) % $grid; $at <= $length; $at += $grid) {
            $marks[] = $at;
        }
        foreach ($blocked as [, $end]) {
            $marks[] = $end;
        }
        foreach ($fixed as $anchor) {
            $marks[] = $anchor['at'] - MealPlacement::MINIMUM_GAP_MINUTES;
            $marks[] = $anchor['at'] + MealPlacement::MINIMUM_GAP_MINUTES;
        }
        foreach ([$this->pre, $this->post] as $range) {
            if ($range !== null) {
                array_push($marks, $range['lo'], $range['target'], $range['hi']);
            }
        }
        $candidates = array_values(array_filter(
            array_unique($marks),
            fn (int $at) => $at >= 0 && $at <= $length && ! $this->isBlocked($at),
        ));

        $this->free = array_keys(array_filter($meals, fn (MealSlot $meal) => $meal->fixedTime === null));
        $left = count($this->free);
        $this->placePost = $this->post !== null && $postFixed === null && $left > 0
            && in_array(MealRole::PostWorkout, $peri, true);
        if ($this->placePost) {
            $left--;
        }
        $this->placePre = $this->pre !== null && $preFixed === null && $left > 0
            && in_array(MealRole::PreWorkout, $peri, true);
        if ($this->placePre) {
            $left--;
        }
        $this->regulars = $left;
        $this->roles = array_merge(
            $preFixed !== null || $this->placePre ? [MealRole::PreWorkout] : [],
            $postFixed !== null || $this->placePost ? [MealRole::PostWorkout] : [],
        );

        // Fixed meals first, so at one offset a fixed meal comes before a free time (the sort is stable).
        $nodes = [];
        foreach ($fixed as $anchor) {
            $role = match ($anchor['meal']) {
                $preFixed['meal'] ?? null => MealRole::PreWorkout,
                $postFixed['meal'] ?? null => MealRole::PostWorkout,
                default => MealRole::Regular,
            };
            $nodes[] = ['at' => $anchor['at'], 'fixed' => ['meal' => $anchor['meal'], 'role' => $role]];
        }
        foreach ($candidates as $at) {
            $nodes[] = ['at' => $at, 'fixed' => null];
        }
        usort($nodes, fn (array $a, array $b) => $a['at'] <=> $b['at']);
        $this->nodeAt = array_column($nodes, 'at');
        $this->nodeFixed = array_column($nodes, 'fixed');
    }

    /**
     * The best placement as a dynamic programme over the nodes in time order. A state is (last node used, regular
     * meals placed, peri-workout roles passed). A state's score is first its drift, the total minutes the
     * peri-workout meals sit away from their targets (lower wins), then its spread, the sum of the logs of the gaps
     * between meals (higher wins).
     *
     * @return list<PlacedMeal>|null
     */
    public function solve(): ?array
    {
        if ($this->meals === []) {
            return [];
        }
        $count = count($this->nodeAt);
        $stages = count($this->roles) + 1;
        $perNode = ($this->regulars + 1) * $stages;
        // The table, flat: state (node, placed, stage) is at (node × (regulars + 1) + placed) × stages + stage.
        $index = fn (int $node, int $placed, int $stage): int => ($node * ($this->regulars + 1) + $placed) * $stages + $stage;

        /** @var array<int, int> $drift */
        $drift = [];
        /** @var array<int, float> $spread */
        $spread = [];
        /** @var array<int, int|null> $previous Flat index of the state this one came from; null for the first meal. */
        $previous = [];
        /** @var array<int, MealRole> $role */
        $role = [];

        // A path may not skip a fixed meal, so a node's predecessors start at the last fixed meal before it.
        $lastFixed = null;
        $lastFixedBefore = [];
        for ($j = 0; $j < $count; $j++) {
            $lastFixedBefore[$j] = $lastFixed;
            if ($this->nodeFixed[$j] !== null) {
                $lastFixed = $j;
            }
        }

        for ($j = 0; $j < $count; $j++) {
            $atJ = $this->nodeAt[$j];
            $fixedJ = $this->nodeFixed[$j] !== null;
            foreach ($this->uses($j) as $use) {
                $nodeDrift = $this->drift($j, $use);
                // From the start: the first meal of the day.
                if ($lastFixedBefore[$j] === null && ($next = $this->step($j, 0, 0, $use)) !== null) {
                    $to = $index($j, $next[0], $next[1]);
                    if (! isset($drift[$to]) || self::beats($nodeDrift, 0.0, $drift[$to], $spread[$to])) {
                        [$drift[$to], $spread[$to], $previous[$to], $role[$to]] = [$nodeDrift, 0.0, null, $use];
                    }
                }
                // Each (placed, stage) maps to one next state for this node and use, whatever the predecessor.
                $nextOf = [];
                for ($placed = 0; $placed <= $this->regulars; $placed++) {
                    for ($stage = 0; $stage < $stages; $stage++) {
                        $next = $this->step($j, $placed, $stage, $use);
                        $nextOf[$placed * $stages + $stage] = $next === null ? null : $index($j, $next[0], $next[1]);
                    }
                }
                for ($i = $lastFixedBefore[$j] ?? 0; $i < $j; $i++) {
                    $gap = $atJ - $this->nodeAt[$i];
                    $bothFixed = $fixedJ && $this->nodeFixed[$i] !== null;
                    $logGap = log((float) max($gap, 1));
                    $base = $i * $perNode;
                    foreach ($nextOf as $offset => $to) {
                        $from = $base + $offset;
                        if ($to === null || ! isset($drift[$from])) {
                            continue;
                        }
                        $periPair = $role[$from] === MealRole::PreWorkout && $use === MealRole::PostWorkout;
                        if ($gap < 0 || ! ($bothFixed || $periPair || $gap >= MealPlacement::MINIMUM_GAP_MINUTES)) {
                            continue;
                        }
                        $newDrift = $drift[$from] + $nodeDrift;
                        $newSpread = $periPair ? $spread[$from] : $spread[$from] + $logGap;
                        if (! isset($drift[$to]) || self::beats($newDrift, $newSpread, $drift[$to], $spread[$to])) {
                            [$drift[$to], $spread[$to], $previous[$to], $role[$to]] = [$newDrift, $newSpread, $from, $use];
                        }
                    }
                }
            }
        }

        $best = null;
        for ($j = $lastFixed ?? 0; $j < $count; $j++) {
            $at = $index($j, $this->regulars, count($this->roles));
            if (isset($drift[$at]) && ($best === null || self::beats($drift[$at], $spread[$at], $drift[$best], $spread[$best]))) {
                $best = $at;
            }
        }
        if ($best === null) {
            return null;
        }

        $path = [];
        for ($cursor = $best; $cursor !== null; $cursor = $previous[$cursor]) {
            $path[] = ['node' => intdiv($cursor, $perNode), 'role' => $role[$cursor]];
        }

        $free = $this->free;

        return array_map(function (array $step) use (&$free): PlacedMeal {
            $fixed = $this->nodeFixed[$step['node']];
            if ($fixed !== null) {
                $meal = $this->meals[$fixed['meal']];

                return new PlacedMeal($meal->id, $meal->fixedTime ?? $this->origin, $step['role']);
            }
            $meal = $this->meals[array_shift($free)];

            return new PlacedMeal($meal->id, $this->origin->adding($this->nodeAt[$step['node']]), $step['role']);
        }, array_reverse($path));
    }

    private static function beats(int $drift, float $spread, int $otherDrift, float $otherSpread): bool
    {
        return $drift !== $otherDrift ? $drift < $otherDrift : $spread > $otherSpread + 1e-9;
    }

    private function isBlocked(int $at): bool
    {
        foreach ($this->blocked as [$start, $end]) {
            if ($at >= $start && $at < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a node may be: a fixed meal is itself; a free time can take any meal still to place.
     *
     * @return list<MealRole>
     */
    private function uses(int $node): array
    {
        $fixed = $this->nodeFixed[$node];
        if ($fixed !== null) {
            return [$fixed['role']];
        }
        $at = $this->nodeAt[$node];
        $uses = [];
        if ($this->regulars > 0) {
            $uses[] = MealRole::Regular;
        }
        if ($this->placePre && $this->pre !== null && $at >= $this->pre['lo'] && $at <= $this->pre['hi']) {
            $uses[] = MealRole::PreWorkout;
        }
        if ($this->placePost && $this->post !== null && $at >= $this->post['lo'] && $at <= $this->post['hi']) {
            $uses[] = MealRole::PostWorkout;
        }

        return $uses;
    }

    /**
     * The state (placed, stage) after using a node, or null when the use breaks the order. Regular meals sit before
     * the pre-workout meal or after the post-workout meal, never between.
     *
     * @return array{int, int}|null
     */
    private function step(int $node, int $placed, int $stage, MealRole $use): ?array
    {
        if ($use === MealRole::Regular) {
            if ($stage !== 0 && $stage !== count($this->roles)) {
                return null;
            }
            if ($this->nodeFixed[$node] !== null) {
                return [$placed, $stage];
            }

            return $placed < $this->regulars ? [$placed + 1, $stage] : null;
        }

        return array_search($use, $this->roles, true) === $stage ? [$placed, $stage + 1] : null;
    }

    private function drift(int $node, MealRole $use): int
    {
        if ($this->nodeFixed[$node] !== null) {
            return 0;
        }

        return match ($use) {
            MealRole::Regular => 0,
            MealRole::PreWorkout => abs($this->nodeAt[$node] - ($this->pre['target'] ?? 0)),
            MealRole::PostWorkout => abs($this->nodeAt[$node] - ($this->post['target'] ?? 0)),
        };
    }
}
