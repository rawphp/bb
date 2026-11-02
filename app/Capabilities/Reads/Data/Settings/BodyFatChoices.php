<?php

namespace App\Capabilities\Reads\Data\Settings;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The body-fat buckets for each sex (TBL-001), so the form can switch with the sex picker. */
final class BodyFatChoices extends CapabilityData
{
    /**
     * @param  list<Choice>  $male
     * @param  list<Choice>  $female
     */
    public function __construct(
        #[Field(items: Choice::class)]
        public readonly array $male,
        #[Field(items: Choice::class)]
        public readonly array $female,
    ) {}
}
