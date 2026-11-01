<?php

namespace App\Capabilities\Today\Data;

use Illuminate\Validation\Rule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Change the training weekdays from the next plan week (SCH-039). */
final class TrainingWeekdaysChangeInput extends CapabilityData
{
    /**
     * @param  list<int>  $weekdays
     */
    public function __construct(
        #[Field(description: 'ISO weekdays; 1 is Monday.')] public readonly array $weekdays,
        #[Field(description: 'Null until the server asks for it with a validation error, when there are fewer days.', enum: ['restructure', 'extend'])] public readonly ?string $fewer_days = null,
    ) {}

    /** Field's enum cannot list null, and without it an explicit null fails the enum check. */
    public static function jsonSchema(): array
    {
        $schema = parent::jsonSchema();
        $schema['properties']['fewer_days']['enum'][] = null;

        return $schema;
    }

    public static function rules(): array
    {
        return [
            'weekdays.*' => [Rule::numeric()->integer(strict: true)->between(1, 7)],
        ];
    }
}
