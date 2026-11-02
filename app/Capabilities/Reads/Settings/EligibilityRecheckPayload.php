<?php

namespace App\Capabilities\Reads\Settings;

use App\Capabilities\Reads\Data\EligibilityRecheckShowOutput;
use App\Capabilities\Reads\Data\Settings\ScreeningQuestion;
use App\Capabilities\Reads\Data\Settings\TextLine;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Safety\EligibilityMode;
use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;
use App\Models\Profile;
use App\Support\Settings\ProfileScreening;
use DateTimeImmutable;

/**
 * eligibility_recheck_show (SAFE-017): every SAFE-001 item in the spec's order, the ticked ones prefilled from the
 * profile and the measured ones worked out again, with the mode the last check left and why.
 */
final class EligibilityRecheckPayload
{
    public static function build(Profile $profile, DateTimeImmutable $today): EligibilityRecheckShowOutput
    {
        $ticked = ProfileScreening::ticked($profile);
        $measured = ProfileScreening::measured($profile, $today);

        return new EligibilityRecheckShowOutput(
            mode: self::mode($profile),
            reasons: self::reasons($profile),
            questions: array_map(fn (ScreeningItem $item) => new ScreeningQuestion(
                item: $item->value,
                label: $item->reason(),
                applies: in_array($item, ProfileScreening::isMeasured($item) ? $measured : $ticked, true),
                measured: ProfileScreening::isMeasured($item),
            ), ScreeningItem::cases()),
        );
    }

    private static function mode(Profile $profile): Status
    {
        if ($profile->training_only) {
            return new Status('Training only', Tone::Warning->value);
        }

        // SAFE-001: what the saved items allow; training_only above wins, since a passing user stays training only
        // until the nutrition steps are done.
        return match (Screening::eligibilityFor(ProfileScreening::saved($profile))->mode) {
            EligibilityMode::AllTargets => new Status('Training and nutrition', Tone::Good->value),
            EligibilityMode::FatLossBlocked => new Status('Training and nutrition, no fat loss', Tone::Info->value),
            EligibilityMode::TrainingOnly => new Status('Training only', Tone::Warning->value),
        };
    }

    /**
     * SAFE-003: why a training-only user is training only. One whose answers now pass is training only until the
     * nutrition steps are done (ONB-005).
     *
     * @return list<TextLine>
     */
    private static function reasons(Profile $profile): array
    {
        if (! $profile->training_only) {
            return [];
        }
        $saved = ProfileScreening::saved($profile);
        if ($saved === []) {
            return [new TextLine('Your answers allow nutrition targets now. Finish the nutrition steps to turn them on.')];
        }

        return array_map(fn (ScreeningItem $item) => new TextLine($item->reason().', so the app does not set calorie targets.'), $saved);
    }
}
