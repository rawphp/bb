<?php

namespace App\Domain\Safety;

use App\Domain\Model\UnitConversion;

/** SAFE-001: the health screening list, in the spec's order. */
enum ScreeningItem: string
{
    case AgeOutOfRange = 'ageOutOfRange';
    case HeightOutOfRange = 'heightOutOfRange';
    case WeightOverLimit = 'weightOverLimit';
    case BmiUnderLimit = 'bmiUnderLimit';
    case PregnantOrBreastfeeding = 'pregnantOrBreastfeeding';
    case TryingToConceive = 'tryingToConceive';
    case TransgenderOnHormoneTherapy = 'transgenderOnHormoneTherapy';
    case HeartThyroidOrPCOS = 'heartThyroidOrPCOS';
    case WeightLossSurgery = 'weightLossSurgery';
    case GallbladderDisease = 'gallbladderDisease';
    case AutoimmuneDisease = 'autoimmuneDisease';
    case Diabetes = 'diabetes';
    case EatingDisorderHistory = 'eatingDisorderHistory';

    /** SAFE-001: every item but "trying to conceive" blocks all calorie targets; that one blocks Fat loss only. */
    public function blocksAllTargets(): bool
    {
        return $this !== self::TryingToConceive;
    }

    /** SAFE-003: why targets are limited. The measured items state the limits `Screening` checks. */
    public function reason(): string
    {
        return match ($this) {
            self::AgeOutOfRange => sprintf('You are under %d or over %d', Screening::MINIMUM_AGE, Screening::MAXIMUM_AGE),
            self::HeightOutOfRange => sprintf(
                'Your height is under %s or over %s',
                self::inchesAndCm(Screening::MINIMUM_HEIGHT_CM), self::inchesAndCm(Screening::MAXIMUM_HEIGHT_CM)),
            self::WeightOverLimit => sprintf(
                'Your weight is over %d lb (%d kg)',
                round(Screening::MAXIMUM_WEIGHT_LB), round(UnitConversion::lbToKg(Screening::MAXIMUM_WEIGHT_LB))),
            self::BmiUnderLimit => 'Your BMI is under '.Screening::MINIMUM_BMI,
            self::PregnantOrBreastfeeding => 'You are pregnant or breastfeeding',
            self::TryingToConceive => 'You are trying to conceive or in fertility treatment',
            self::TransgenderOnHormoneTherapy => 'You are on hormone therapy',
            self::HeartThyroidOrPCOS => 'You have heart disease, thyroid cancer, PCOS, or hyper- or hypothyroidism',
            self::WeightLossSurgery => 'You had weight-loss surgery in the past 5 years',
            self::GallbladderDisease => 'You have gallbladder disease',
            self::AutoimmuneDisease => 'You have an autoimmune disease',
            self::Diabetes => 'You have type 1 or type 2 diabetes',
            self::EatingDisorderHistory => 'You have a history of eating disorders',
        };
    }

    private static function inchesAndCm(float $cm): string
    {
        return sprintf('%d in (%d cm)', round($cm / UnitConversion::CM_PER_INCH), round($cm));
    }
}
