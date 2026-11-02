<?php

namespace App\Capabilities\Nutrition;

use App\Ai\FoodAi;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ScanLabelInput;
use App\Capabilities\Nutrition\Data\ScanLabelOutput;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Scan\LabelDraft;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;

final class ScanLabelCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('scan_label')
            ->description('Read a photo of a nutrition label into a custom food draft: name, brand, a serving and its protein, fat, carbs and fiber. Use it when a barcode found nothing or the user photographs a label; pass that barcode so the new food carries it, and save the confirmed draft with custom_food_save. It saves nothing.')
            ->cli('scan', 'label')
            ->input(ScanLabelInput::class)
            ->output(ScanLabelOutput::class)
            ->run(fn (ScanLabelInput $input): ScanLabelOutput => $this->run($input));
    }

    /** FOOD-042 */
    private function run(ScanLabelInput $input): ScanLabelOutput
    {
        // Resolved per call: capabilities are built at boot, and the AI key the call checks can change after.
        $label = app(FoodAi::class)->label($input->image);
        $draft = LabelDraft::perServing($label->servingGrams, $label->kcal, $label->protein, $label->fat, $label->carbs, $label->fiber);

        return new ScanLabelOutput(
            name: $label->name,
            brand: $label->brand,
            barcode: $input->barcode,
            serving_size: $draft->servingGrams,
            serving_unit: 'g',
            serving_grams: $draft->servingGrams,
            protein: $draft->protein,
            fat: $draft->fat,
            carbs: $draft->carbs,
            fiber: $draft->fiber,
            kcal_text: ScanDrafts::kcal($draft->kcal),
        );
    }
}
