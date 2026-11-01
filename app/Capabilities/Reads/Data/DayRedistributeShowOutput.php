<?php

namespace App\Capabilities\Reads\Data;

use App\Capabilities\Reads\Data\Today\RedistributeRow;
use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** The Redistribute picker with what each chosen meal would hold (DAY-002, DAY-003, DAY-021, SAFE-018). */
final class DayRedistributeShowOutput extends CapabilityData
{
    /**
     * @param  list<RedistributeRow>  $rows
     * @param  list<Status>  $warnings
     */
    public function __construct(
        #[Field(format: 'date')] public readonly string $date,
        public readonly string $note,
        #[Field(items: RedistributeRow::class)] public readonly array $rows,
        #[Field(items: Status::class)] public readonly array $warnings,
        public readonly bool $can_confirm,
    ) {}

    public static function rules(): array
    {
        return [
            'date' => ['date_format:Y-m-d'],
        ];
    }
}
