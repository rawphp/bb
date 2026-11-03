<?php

namespace App\Actions\Settings;

use App\Actions\Plan\RebuildTargets;
use App\Domain\Plan\PlanRebuildRefused;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SET-004: a settings write. The profile saves first, and that save stands whatever the rebuild does. Then, when the
 * change touched a target input or a rebuild that failed is still pending, the targets after today are rebuilt in
 * their own savepoint, so a failure rolls back only the rebuild:
 *
 * - it runs, or the rules refuse it (the targets stay as they were): nothing is pending;
 * - anything else: the failure is reported, and `targets_rebuild_failed_at` holds the time until a later save
 *   rebuilds, which settings_show shows as "Tap Save to try again";
 * - except a deadlock or lock wait: MySQL has then rolled back more than the savepoint, the profile save included, so
 *   the whole write fails, and is tried again when this is the outermost transaction.
 *
 * The plan row is locked before the profile, the order RebuildTargets takes them in.
 *
 * Every profile field is a target input except the ones in NOT_TARGET_INPUTS, rp-clone's
 * `SettingsModel.targetInputs`, so a field added later rebuilds by default.
 */
final class SaveProfile
{
    public const NO_PROFILE = "Your profile isn't set up yet. Finish setting up first.";

    /** The profile fields the targets are never computed from. */
    public const NOT_TARGET_INPUTS = [
        'name', 'weight_unit', 'height_unit', 'appearance', 'consumed_totals', 'micro_plates', 'diet_filters', 'weigh_in_days',
    ];

    /** Tries of the whole write when the database breaks a deadlock or lock wait. */
    private const ATTEMPTS = 3;

    /** Bookkeeping, not answers. */
    private const NOT_FIELDS = ['id', 'user_id', 'created_at', 'updated_at', 'targets_rebuild_failed_at'];

    /**
     * @param  array<string, mixed>  $changes  profile attributes
     * @param  (Closure(Profile): void)|null  $check  runs on the locked, unchanged profile before anything saves; it
     *                                                refuses by throwing
     * @return list<string> the dates the rebuild wrote, Y-m-d; empty when it did not run or did not finish
     */
    public function __invoke(User $user, array $changes, ?Closure $check = null): array
    {
        return DB::transaction(function () use ($user, $changes, $check): array {
            Plan::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $profile = Profile::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? throw new DomainException(self::NO_PROFILE);
            if ($check !== null) {
                $check($profile);
            }

            $profile->fill($changes);
            $changesTargets = array_diff(self::changed($profile), self::NOT_TARGET_INPUTS, self::NOT_FIELDS) !== [];
            $pending = $profile->targets_rebuild_failed_at !== null;
            $profile->save();

            if (! $changesTargets && ! $pending) {
                return [];
            }

            [$dates, $failedAt] = $this->rebuild($user);
            $profile->forceFill(['targets_rebuild_failed_at' => $failedAt])->save();

            return $dates;
        }, self::ATTEMPTS);
    }

    /**
     * The fields the fill changed. A JSON column comes back from the database with its keys in another order, which
     * Eloquent counts as a change, so lists and objects are compared by value.
     *
     * @return list<string>
     */
    private static function changed(Profile $profile): array
    {
        return array_values(array_filter(
            array_keys($profile->getDirty()),
            fn (string $field) => ! is_array($profile->getAttribute($field))
                || self::canonical($profile->getAttribute($field)) !== self::canonical($profile->getOriginal($field)),
        ));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array{0: list<string>, 1: ?CarbonImmutable} the dates written, and when it failed */
    private function rebuild(User $user): array
    {
        try {
            // Resolved at each call, so a test can stand in for it.
            return [DB::transaction(fn (): array => app(RebuildTargets::class)($user, beforeStartBuildsFirstWeek: true)), null];
        } catch (PlanRebuildRefused) {
            return [[], null];
        } catch (DeadlockException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return [[], CarbonImmutable::now()];
        }
    }
}
