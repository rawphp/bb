<?php

namespace App\Actions\Settings;

use App\Capabilities\Settings\Data\ProfileUpdateInput;
use App\Capabilities\Support\FieldRefused;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\UnitConversion;
use App\Models\User;

/**
 * SET-004: the profile form saves name, sex, date of birth, height, body-fat bucket, GLP-1 answer and experience, then
 * rebuilds the targets when one of them reaches them ({@see SaveProfile}). The height comes in the unit it was typed
 * in and is stored in cm (SET-001); the height unit setting itself is settings_update's.
 */
final class UpdateProfile
{
    public const HEIGHT_MISSING = 'Enter your height.';

    public function __construct(private readonly SaveProfile $save) {}

    /** @return list<string> the dates the rebuild wrote, Y-m-d */
    public function __invoke(User $user, ProfileUpdateInput $input): array
    {
        return ($this->save)($user, [
            'name' => trim($input->name),
            'sex' => $input->sex,
            'birth_date' => $input->birth_date,
            'height_cm' => self::heightCm($input->height->unit, $input->height->cm, $input->height->feet, $input->height->inches),
            'body_fat_bucket' => $input->body_fat_bucket,
            'uses_glp1' => $input->uses_glp1,
            'experience' => $input->experience,
        ]);
    }

    /** The height in cm, from cm or from feet and inches; more than nothing either way. */
    public static function heightCm(string $unit, ?float $cm, ?int $feet, ?float $inches): float
    {
        $height = HeightUnit::from($unit) === HeightUnit::Cm
            ? $cm
            : ($feet === null && $inches === null ? null : UnitConversion::feetInchesToCm($feet ?? 0, $inches ?? 0.0));

        if ($height === null || $height <= 0) {
            throw new FieldRefused('height', self::HEIGHT_MISSING);
        }

        return round($height, 1);
    }
}
