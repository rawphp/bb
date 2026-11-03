<?php

namespace App\Actions\Activity;

use App\Capabilities\Reads\Data\Settings\ActivityEntry;
use App\Capabilities\Settings\TimeZoneSetCapability;
use App\Capabilities\Support\Channel;
use App\Capabilities\Support\ErrorWording;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Models\AuditRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Support\CapabilityResult;

/**
 * The user's Activity (CAP-022): their last 100 capability invokes from
 * audit_records, newest first, each worded for the screen.
 */
final class ListActivity
{
    public const LIMIT = 100;

    /** The audit events of a capability invoke. Approval and tool-boundary events have no write of their own to show. */
    public const INVOKE_EVENTS = ['capability.invoked', 'capability.failed', 'capability.output_invalid'];

    /** Writes the app makes on its own, audited but not the user's doing: the device's time zone (SET-012). */
    public const HIDDEN = [TimeZoneSetCapability::NAME];

    /** One audit record in plain words (CAP-022). */
    public static function entry(AuditRecord $record, CarbonInterface $now): ActivityEntry
    {
        $result = $record->result ?? [];
        $ok = ($result['ok'] ?? false) === true;
        $at = CarbonImmutable::instance($record->created_at);
        // __invoke lists only rows with one of the five channels.
        $channel = Channel::from((string) $record->channel);

        return new ActivityEntry(
            id: $record->id,
            title: self::title($record),
            channel: $channel->value,
            channel_label: $channel->label(),
            occurred_at: $at->toIso8601String(),
            when_text: self::when($at, CarbonImmutable::instance($now)),
            result: self::status($record, $result, $ok),
        );
    }

    /**
     * A write's title: its toast when it succeeded with one, else its capability name in words.
     * The Coach's change cards use it too (AGT-013).
     */
    public static function title(AuditRecord $record): string
    {
        $result = $record->result ?? [];
        $toast = $result['summary']['toast'] ?? null;

        if (($result['ok'] ?? false) === true && is_string($toast) && $toast !== '') {
            return $toast;
        }

        return $record->capability === null ? 'Change' : Str::ucfirst(str_replace('_', ' ', $record->capability));
    }

    /**
     * The user's last LIMIT invokes, newest first, each in plain words.
     *
     * @return list<ActivityEntry>
     */
    public function __invoke(User $user, CarbonInterface $now): array
    {
        $records = AuditRecord::query()
            ->whereBelongsTo($user)
            ->whereIn('event', self::INVOKE_EVENTS)
            ->where(fn ($query) => $query->whereNull('capability')->orWhereNotIn('capability', self::HIDDEN))
            ->whereIn('channel', array_column(Channel::cases(), 'value'))
            ->latest()
            ->latest('id')
            ->limit(self::LIMIT)
            // The input is audited but not shown, so it is not loaded.
            ->get(['id', 'event', 'capability', 'channel', 'result', 'created_at']);

        return $records->map(fn (AuditRecord $record): ActivityEntry => self::entry($record, $now))->values()->all();
    }

    /**
     * output_invalid is a server bug after run() committed (D-014): the change was made.
     *
     * @param  array<string, mixed>  $result
     */
    private static function status(AuditRecord $record, array $result, bool $ok): Status
    {
        return match (true) {
            $record->event === 'capability.output_invalid' => new Status('Done, with a server error', Tone::Warning->value),
            $record->result === null => new Status('No result recorded', Tone::Neutral->value),
            $ok => new Status('Done', Tone::Good->value),
            default => new Status(self::refusal($result), Tone::Warning->value),
        };
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
