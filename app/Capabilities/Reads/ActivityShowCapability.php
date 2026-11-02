<?php

namespace App\Capabilities\Reads;

use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Data\ActivityShowInput;
use App\Capabilities\Reads\Data\ActivityShowOutput;
use App\Capabilities\Reads\Data\Settings\ActivityEntry;
use App\Capabilities\Reads\Data\Settings\Status;
use App\Capabilities\Support\Channel;
use App\Capabilities\Support\ErrorWording;
use App\Capabilities\Support\HostDefinition;
use App\Capabilities\Support\Tone;
use App\Models\AuditRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;
use Rawphp\Capabilities\Support\CapabilityResult;

final class ActivityShowCapability implements HostCapability
{
    public const LIMIT = 100;

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('activity_show')
            ->description('Show the signed-in user\'s last 100 changes, newest first: what changed, through which channel (app, Coach, MCP, CLI or Health bridge), when, and whether it was done or refused. It changes nothing.')
            ->cli('activity', 'show')
            ->input(ActivityShowInput::class)
            ->output(ActivityShowOutput::class)
            ->run(fn (ActivityShowInput $input, CapabilityContext $context): ActivityShowOutput => $this->run($context));
    }

    /** One audit record in plain words (CAP-022). */
    public static function entry(AuditRecord $record, CarbonInterface $now): ActivityEntry
    {
        $result = $record->result ?? [];
        $ok = ($result['ok'] ?? false) === true;
        $toast = $result['summary']['toast'] ?? null;
        $name = Str::ucfirst(str_replace('_', ' ', (string) $record->capability));
        $at = CarbonImmutable::instance($record->created_at);

        return new ActivityEntry(
            id: $record->id,
            title: $ok && is_string($toast) && $toast !== '' ? $toast : $name,
            channel: $record->channel,
            channel_label: Channel::from($record->channel)->label(),
            occurred_at: $at->toIso8601String(),
            when_text: self::when($at, CarbonImmutable::instance($now)),
            result: $ok
                ? new Status('Done', Tone::Good->value)
                : new Status(self::refusal($result), Tone::Warning->value),
        );
    }

    private function run(CapabilityContext $context): ActivityShowOutput
    {
        /** @var User $user */
        $user = $context->user();
        $now = CarbonImmutable::now();

        $records = AuditRecord::query()
            ->whereBelongsTo($user)
            ->latest()
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return new ActivityShowOutput(
            entries: $records->map(fn (AuditRecord $record): ActivityEntry => self::entry($record, $now))->values()->all(),
        );
    }

    /** @param  array<string, mixed>  $result */
    private static function refusal(array $result): string
    {
        $failure = CapabilityResult::failure((string) ($result['code'] ?? 'internal'), (string) ($result['message'] ?? ''));
        $message = (string) (ErrorWording::apply($failure)->error['message'] ?? '');

        $message = Str::lcfirst(rtrim($message, '.'));

        return $message === '' ? 'Refused' : 'Refused: '.$message;
    }

    private static function when(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $at = $at->setTimezone($now->getTimezone());

        return match (true) {
            $at->isSameDay($now) => 'Today, '.$at->format('H:i'),
            $at->isSameDay($now->subDay()) => 'Yesterday, '.$at->format('H:i'),
            $at->isSameYear($now) => $at->format('D j M, H:i'),
            default => $at->format('j M Y, H:i'),
        };
    }
}
