<?php

namespace App\Capabilities\Settings\Data;

use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** eligibility_recheck: the screening answers (SAFE-017). Measured items are worked out again on the server. */
final class EligibilityRecheckInput extends CapabilityData
{
    /**
     * @param  list<ScreeningAnswerInput>  $answers
     */
    public function __construct(
        #[Field(items: ScreeningAnswerInput::class)]
        public readonly array $answers,
    ) {}

    /**
     * SAFE-001: the user confirms each item that can be ticked, so one left out is refused rather than read as not
     * applying, which would clear a saved answer. The measured items may be left out.
     */
    public static function rules(): array
    {
        return [
            'answers' => [new class implements ValidationRule
            {
                public function validate(string $attribute, mixed $value, Closure $fail): void
                {
                    $given = array_column(is_array($value) ? $value : [], 'item');
                    foreach (ScreeningItem::cases() as $item) {
                        if (! in_array($item, Screening::MEASURED_KINDS, true) && ! in_array($item->value, $given, true)) {
                            $fail('Answer every screening item.');

                            return;
                        }
                    }
                }
            }],
            'answers.*.item' => ['distinct'],
        ];
    }
}
