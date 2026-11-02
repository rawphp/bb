<?php

namespace App\Capabilities\Reads\Train;

use App\Capabilities\Mesocycles\Data\MesocyclePriorityRow;
use App\Capabilities\Mesocycles\Data\TemplateDay;
use App\Capabilities\Mesocycles\Data\TemplateDetail;
use App\Capabilities\Mesocycles\Data\TemplateFilter;
use App\Capabilities\Mesocycles\Data\TemplateFilterOption;
use App\Capabilities\Mesocycles\Data\TemplateRow;
use App\Capabilities\Mesocycles\Data\TemplateSlot as TemplateSlotRow;
use App\Capabilities\Mesocycles\Data\TemplatesShowInput;
use App\Capabilities\Mesocycles\Data\TemplatesShowOutput;
use App\Capabilities\Mesocycles\Data\TemplateWeeklySets;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use App\Models\Template;
use App\Models\TemplateSlot;
use Illuminate\Support\Collection;

/**
 * The template library (MESO-010, MESO-011, MESO-015) and one template's detail (MESO-012), from every template.
 *
 * MESO-011: a filter left out is All, except the phase, which opens on the phase in force; `all` clears it. A
 * template for anyone fits either sex, and emphasis is a specialisation split. Rows go by days, then name.
 */
final class TemplatesPayload
{
    public const NO_MATCHES = 'No templates match these filters.';

    private const PHASE_ALL = 'all';

    /** @param  Collection<int, Template>  $templates  every template */
    public static function build(Collection $templates, TemplatesShowInput $input, ?PhaseType $phaseNow, ?Template $detail): TemplatesShowOutput
    {
        $phase = $input->phase ?? ($phaseNow === null ? self::PHASE_ALL : PhaseFit::for($phaseNow)->value);
        $filters = [
            self::filter('days', 'Days', $input->days, null, [
                [null, 'All'], ...array_map(fn (int $days) => [(string) $days, "{$days} days/week", (string) $days], range(2, 6)),
            ]),
            self::filter('sex', 'Sex', $input->sex, null, [[null, 'All'], ['female', 'Female'], ['male', 'Male']]),
            self::filter('focus', 'Focus', $input->focus, null, [[null, 'All'], ['whole_body', 'Whole body'], ['emphasis', 'Emphasis']]),
            self::filter('phase', 'Phase', $phase, self::PHASE_ALL, [
                [self::PHASE_ALL, 'All'], ...array_map(fn (PhaseFit $fit) => [$fit->value, $fit->title()], PhaseFit::cases()),
            ]),
        ];

        $rows = $templates
            ->filter(fn (Template $template) => self::matches($template, $input, $phase))
            ->sortBy([['days_per_week', 'asc'], ['name', 'asc']])
            ->map(fn (Template $template) => new TemplateRow((string) $template->id, $template->name, self::summary($template)))
            ->values()->all();

        return new TemplatesShowOutput(
            filters: $filters,
            has_active_filters: array_any($filters, fn (TemplateFilter $filter) => $filter->active),
            rows: $rows,
            empty_text: $rows === [] ? self::NO_MATCHES : null,
            detail: $detail === null ? null : self::detail($detail),
        );
    }

    /** MESO-011. */
    private static function matches(Template $template, TemplatesShowInput $input, string $phase): bool
    {
        return ($input->days === null || $template->days_per_week === (int) $input->days)
            && ($input->sex === null || in_array($template->sex, [SexFit::Any, SexFit::from($input->sex)], true))
            && ($input->focus === null || ($template->split === Split::Specialisation) === ($input->focus === 'emphasis'))
            && ($phase === self::PHASE_ALL || $template->phase_fit->contains(PhaseFit::from($phase)));
    }

    /** MESO-015: "4 days/week · Upper/lower". */
    private static function summary(Template $template): string
    {
        return "{$template->days_per_week} days/week · {$template->split->title()}";
    }

    /** @param  list<array{0: ?string, 1: string, 2?: string}>  $options  value, label and the chip's shorter label if any, All first */
    private static function filter(string $key, string $label, ?string $selected, ?string $all, array $options): TemplateFilter
    {
        $chosen = array_find($options, fn (array $option) => $option[0] === $selected) ?? $options[0];

        return new TemplateFilter(
            key: $key,
            label: "{$label}: ".($chosen[2] ?? $chosen[1]),
            active: $chosen[0] !== $all,
            options: array_map(fn (array $option) => new TemplateFilterOption($option[0], $option[1], $option[0] === $chosen[0]), $options),
        );
    }

    /** MESO-012: the days with their exercises by muscle slot, the template's priorities and weekly sets per muscle. */
    private static function detail(Template $template): TemplateDetail
    {
        /** @var Collection<int, TemplateSlot> $slots */
        $slots = $template->slots->sortBy([['day', 'asc'], ['position', 'asc']])->values();
        $labels = $template->day_labels ?? [];
        $priorities = $template->priorities ?? [];

        return new TemplateDetail(
            id: (string) $template->id,
            name: $template->name,
            summary: self::summary($template),
            days: array_map(fn (int $day) => new TemplateDay(
                label: $labels[$day - 1] ?? "Day {$day}",
                slots: $slots->filter(fn (TemplateSlot $slot) => $slot->day === $day)->map(fn (TemplateSlot $slot) => new TemplateSlotRow(
                    exercise_name: $slot->exercise->name ?? '',
                    muscle_title: $slot->muscle_group->title(),
                    sets_text: TrainWords::count($slot->sets, 'set'),
                ))->values()->all(),
            ), range(1, max(1, $template->days_per_week))),
            // Only the muscles the template gives a priority, in MESO-033 order.
            priorities: array_values(array_filter(array_map(function (MuscleGroup $muscle) use ($priorities) {
                $priority = Priority::tryFrom((string) ($priorities[$muscle->value] ?? ''));

                return $priority === null ? null : new MesocyclePriorityRow(
                    muscle: $muscle->value, muscle_title: $muscle->title(), priority: $priority->value,
                    priority_title: $priority->title(), bars: $priority->bars(),
                );
            }, MuscleGroup::cases()))),
            weekly_sets: array_values(array_filter(array_map(function (MuscleGroup $muscle) use ($slots) {
                $sets = $slots->filter(fn (TemplateSlot $slot) => $slot->muscle_group === $muscle)->sum('sets');

                return $sets > 0 ? new TemplateWeeklySets($muscle->title(), TrainWords::count((int) $sets, 'set')) : null;
            }, MuscleGroup::cases()))),
        );
    }
}
