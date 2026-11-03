<?php

namespace Tests\Support;

use App\Actions\Plan\RebuildTargets;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A stand-in for RebuildTargets (SET-004) that records its calls and answers as told: the dates it wrote, or the
 * exception. One that throws first makes a write, setting the profile's meal count to 6, so a test can see that the
 * failed rebuild's savepoint rolled it back.
 */
final class FakeRebuild
{
    /** @var list<array{user: int, beforeStart: bool}> */
    public array $calls = [];

    /** @param  list<string>  $dates */
    public function __construct(private readonly ?Throwable $throw = null, private readonly array $dates = ['2026-10-15'], private int $throws = PHP_INT_MAX) {}

    /**
     * Bound in place of RebuildTargets, which callers resolve with app() at each call.
     *
     * @param  int  $times  how many calls throw before the rest succeed
     */
    public static function bind(?Throwable $throw = null, int $times = PHP_INT_MAX): self
    {
        $fake = new self($throw, throws: $times);
        app()->instance(RebuildTargets::class, $fake);

        return $fake;
    }

    /** @return list<string> */
    public function __invoke(User $user, float $calorieChangePerDay = 0, bool $beforeStartBuildsFirstWeek = false): array
    {
        $this->calls[] = ['user' => $user->id, 'beforeStart' => $beforeStartBuildsFirstWeek];
        if ($this->throw !== null && $this->throws-- > 0) {
            DB::table('profiles')->where('user_id', $user->id)->update(['meal_count' => 6]);

            throw $this->throw;
        }

        return $this->dates;
    }
}
