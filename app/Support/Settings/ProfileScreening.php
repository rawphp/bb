<?php

namespace App\Support\Settings;

use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;
use App\Models\Profile;
use App\Support\Plan\PlanSnapshot;
use DateTimeImmutable;

/**
 * The screening as the profile holds it (SAFE-001, SAFE-017). `screening` is every item that applied at the last
 * check, ticked or measured; `training_only` is whether nutrition targets are off. The measured items are worked out
 * from the profile's stored weight, not the trend (WT-011's exception for the re-check).
 */
final class ProfileScreening
{
    /** @return list<ScreeningItem> the saved items, in the spec's order */
    public static function saved(Profile $profile): array
    {
        $saved = array_map(fn (mixed $item) => ScreeningItem::tryFrom((string) $item), (array) ($profile->screening ?? []));

        return array_values(array_filter(ScreeningItem::cases(), fn (ScreeningItem $item) => in_array($item, $saved, true)));
    }

    /** @return list<ScreeningItem> the saved items the user ticked: the measured kinds are never ticked */
    public static function ticked(Profile $profile): array
    {
        return self::withoutMeasuredKinds(self::saved($profile));
    }

    /**
     * @param  list<ScreeningItem>  $items
     * @return list<ScreeningItem>
     */
    public static function withoutMeasuredKinds(array $items): array
    {
        return array_values(array_filter($items, fn (ScreeningItem $item) => ! self::isMeasured($item)));
    }

    /** @return list<ScreeningItem> the measured items that apply today, in the spec's order */
    public static function measured(Profile $profile, DateTimeImmutable $today): array
    {
        return Screening::measuredItemsFrom(
            PlanSnapshot::day($profile->birth_date), (float) $profile->height_cm, (float) $profile->weight_kg, $today);
    }

    public static function isMeasured(ScreeningItem $item): bool
    {
        return in_array($item, Screening::MEASURED_KINDS, true);
    }
}
