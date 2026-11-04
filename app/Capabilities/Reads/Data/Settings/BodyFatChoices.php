<?php

namespace App\Capabilities\Reads\Data\Settings;

use App\Capabilities\Support\Option;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The body-fat buckets for each sex (TBL-001), so the form can switch with the sex picker. */
final class BodyFatChoices extends CapabilityData
{
    /**
     * @param  list<Option>  $male
     * @param  list<Option>  $female
     */
    public function __construct(
        #[Field(items: Option::class)]
        public readonly array $male,
        #[Field(items: Option::class)]
        public readonly array $female,
    ) {}
}
