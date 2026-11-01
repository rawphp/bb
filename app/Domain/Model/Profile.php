<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/** Who the user is (ONB-004 to ONB-010, SAFE-013). Weight in kg, height in cm. */
final readonly class Profile
{
    public function __construct(
        public string $name,
        public Sex $sex,
        public DateTimeImmutable $birthDate,
        public float $heightCm,
        public float $weightKg,
        public WeightUnit $weightUnit,
        public HeightUnit $heightUnit,
        public BodyFatBucket $bodyFatBucket,
        public bool $usesGLP1,
        public ExperienceLevel $experience,
    ) {}
}
