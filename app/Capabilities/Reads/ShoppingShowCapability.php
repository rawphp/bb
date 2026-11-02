<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\ShoppingRow;
use App\Capabilities\Nutrition\Data\ShoppingSection;
use App\Capabilities\Nutrition\Data\ShoppingShowInput;
use App\Capabilities\Nutrition\Data\ShoppingShowOutput;
use App\Capabilities\Nutrition\ShoppingReads;
use App\Capabilities\Reads\Shopping\PlannedShopping;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Shopping\ListLine;
use App\Domain\Shopping\ListSection;
use App\Domain\Shopping\ManualItem;
use App\Domain\Shopping\ShoppingScreen;
use App\Domain\Shopping\TickedRange;
use App\Models\ManualShoppingItem;
use App\Models\ShoppingTicks;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ShoppingShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('shopping_show')
            ->description('Show the shopping list for a date range: the foods planned in those days\' meals, recipes broken into ingredients, summed and rounded up to the raw weight to buy, grouped by store category, with the items the user added, which lines are ticked off, and the list as text to share. With no dates it shows the range the user last ticked in while that range has not ended, otherwise the next 7 days; pass from and to for a particular week. It changes nothing.')
            ->cli('shopping', 'show')
            ->input(ShoppingShowInput::class)
            ->output(ShoppingShowOutput::class)
            ->run(function (ShoppingShowInput $input, CapabilityContext $context): ShoppingShowOutput {
                /** @var User $user */
                $user = $context->user();
                $saved = ShoppingTicks::of($user);
                $range = ShoppingReads::shown($input->from, $input->to)
                    ?? TickedRange::rangeToShow($saved, CarbonImmutable::now()->toDateTimeImmutable(), new DateTimeZone('UTC'));

                $manual = ManualShoppingItem::query()->where('user_id', $user->id)->orderBy('id')->get()
                    ->map(fn (ManualShoppingItem $item) => new ManualItem((string) $item->id, $item->text))
                    ->values()
                    ->all();
                $sections = ShoppingScreen::sections(PlannedShopping::groups($user, $range), $manual, TickedRange::keysIn($saved, $range));

                return new ShoppingShowOutput(
                    from: $range->lower->format('Y-m-d'),
                    to: $range->upper->format('Y-m-d'),
                    range_text: ShoppingScreen::rangeText($range),
                    sections: array_map(self::section(...), $sections),
                    share_text: ShoppingScreen::shareText($sections),
                    empty_text: ShoppingScreen::emptyText($sections),
                );
            });
    }

    private static function section(ListSection $section): ShoppingSection
    {
        return new ShoppingSection(
            category: Str::snake($section->category->value),
            title: $section->category->title(),
            rows: array_map(fn (ListLine $line) => new ShoppingRow(
                key: $line->key,
                name: $line->name,
                amount_text: $line->amountText,
                is_ticked: $line->isTicked,
                is_manual: $line->isManual,
            ), $section->lines),
        );
    }
}
