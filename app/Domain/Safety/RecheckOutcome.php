<?php

namespace App\Domain\Safety;

/** SAFE-017: the outcome of a re-check, with the new screening result. */
final readonly class RecheckOutcome
{
    public function __construct(
        public RecheckOutcomeKind $kind,
        public Eligibility $eligibility,
    ) {}

    /**
     * The reasons to show.
     *
     * @return list<ScreeningItem>
     */
    public function reasons(): array
    {
        return $this->eligibility->reasons;
    }

    /** SAFE-017: targets after the nutrition setup start from the formula (NUT-002). */
    public function startsFromFormula(): bool
    {
        return $this->kind === RecheckOutcomeKind::RunNutritionSetup;
    }
}
