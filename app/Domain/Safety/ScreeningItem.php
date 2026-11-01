<?php

namespace App\Domain\Safety;

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

    /** SAFE-003: why targets are limited. */
    public function reason(): string
    {
        return match ($this) {
            self::AgeOutOfRange => 'You are under 18 or over 65',
            self::HeightOutOfRange => 'Your height is under 54 in (137 cm) or over 84 in (213 cm)',
            self::WeightOverLimit => 'Your weight is over 350 lb (159 kg)',
            self::BmiUnderLimit => 'Your BMI is under 17',
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
}
