<?php

namespace App\Actions\Settings;

use App\Domain\Safety\RecheckOutcomeKind;

/** What a re-check did (SAFE-017). */
final readonly class Recheck
{
    /**
     * @param  'done'|'onboarding'  $next  onboarding: the nutrition steps or the goal choice come next
     * @param  list<string>  $phaseIds  the phases written
     * @param  list<string>  $removedDates  Y-m-d of the targets removed
     * @param  list<string>  $weekDates  Y-m-d of every day of the plan weeks those fall in
     */
    public function __construct(
        public RecheckOutcomeKind $kind,
        public string $message,
        public string $next,
        public array $phaseIds = [],
        public array $removedDates = [],
        public array $weekDates = [],
    ) {}
}
