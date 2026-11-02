<?php

namespace App\Capabilities\Support;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ConsumedTotals;
use App\Domain\Model\DietFilter;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Sex;
use App\Domain\Model\WeightUnit;
use App\Domain\Safety\RecheckOutcomeKind;
use App\Domain\Safety\ScreeningItem;

/**
 * The values of each domain enum a contract carries, for #[Field(enum: ...)],
 * since an attribute cannot call ::cases(). Each constant is named after its
 * enum, and DomainEnumValuesTest keeps it equal to that enum's cases.
 */
final class DomainEnumValues
{
    public const RECHECK_OUTCOME_KIND = [
        RecheckOutcomeKind::RunNutritionSetup->value,
        RecheckOutcomeKind::SwitchToTrainingOnly->value,
        RecheckOutcomeKind::LeaveFatLoss->value,
        RecheckOutcomeKind::Keep->value,
    ];

    public const HEIGHT_UNIT = [
        HeightUnit::Cm->value,
        HeightUnit::FeetInches->value,
    ];

    public const WEIGHT_UNIT = [
        WeightUnit::Kg->value,
        WeightUnit::Lb->value,
    ];

    public const CONSUMED_TOTALS = [
        ConsumedTotals::Planned->value,
        ConsumedTotals::CheckedIn->value,
    ];

    public const BODY_FAT_BUCKET = [
        BodyFatBucket::Lowest->value,
        BodyFatBucket::Low->value,
        BodyFatBucket::High->value,
        BodyFatBucket::Highest->value,
    ];

    public const SEX = [
        Sex::Male->value,
        Sex::Female->value,
    ];

    public const EXPERIENCE_LEVEL = [
        ExperienceLevel::Beginner->value,
        ExperienceLevel::Intermediate->value,
        ExperienceLevel::Advanced->value,
    ];

    public const DIET_FILTER = [
        DietFilter::DairyFree->value,
        DietFilter::GlutenFree->value,
        DietFilter::GrainFree->value,
        DietFilter::LowFodmap->value,
        DietFilter::Vegetarian->value,
        DietFilter::Paleo->value,
        DietFilter::Vegan->value,
    ];

    public const SCREENING_ITEM = [
        ScreeningItem::AgeOutOfRange->value,
        ScreeningItem::HeightOutOfRange->value,
        ScreeningItem::WeightOverLimit->value,
        ScreeningItem::BmiUnderLimit->value,
        ScreeningItem::PregnantOrBreastfeeding->value,
        ScreeningItem::TryingToConceive->value,
        ScreeningItem::TransgenderOnHormoneTherapy->value,
        ScreeningItem::HeartThyroidOrPCOS->value,
        ScreeningItem::WeightLossSurgery->value,
        ScreeningItem::GallbladderDisease->value,
        ScreeningItem::AutoimmuneDisease->value,
        ScreeningItem::Diabetes->value,
        ScreeningItem::EatingDisorderHistory->value,
    ];
}
