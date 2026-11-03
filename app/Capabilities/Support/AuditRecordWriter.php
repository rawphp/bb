<?php

namespace App\Capabilities\Support;

use App\Agent\CurrentTurn;
use App\Models\AuditRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as Auth;
use LogicException;
use Rawphp\Capabilities\Contracts\AuditWriter;
use UnexpectedValueException;

/**
 * The package's audit sink (D-010), bound by the host: one audit_records row
 * per entry, with the user and the channel (CAP-022). The package redacts the
 * input before the entry reaches here. Reads are not audited.
 *
 * The channel is derived here because the pipeline calls write() inside the
 * invoke, never from a queue (InvokeAuditStage::record), so over http the
 * request's credential is the invoke's credential. The package builds the entry
 * itself and has no field a host can fill, so the channel cannot travel in it.
 * ActivityTest pins this with a fake queue. An entry that carries recorded_at
 * is dated by it. For the same reason the Coach turn a write was made in is read from
 * {@see CurrentTurn}, which HostBus holds for the invoke (AGT-013).
 */
final class AuditRecordWriter implements AuditWriter
{
    public function __construct(private readonly Auth $auth, private readonly CurrentTurn $turn) {}

    public function write(array $entry): void
    {
        // Approval and tool-boundary events carry no caller, so they have no channel.
        $caller = is_string($entry['caller'] ?? null) ? $entry['caller'] : null;

        AuditRecord::create([
            'user_id' => $this->userId($entry),
            'event' => (string) ($entry['event'] ?? 'capability.unknown'),
            'capability' => $entry['capability_name'] ?? null,
            'channel' => $this->channel($caller),
            'input' => $entry['redacted_input'] ?? null,
            'result' => $entry['result'] ?? null,
            'turn_ulid' => $this->turn->ulid(),
            'created_at' => $this->recordedAt($entry),
        ]);
    }

    /**
     * The contract lets a production writer refuse this. audit_records holds 90 days of
     * every user's writes, so read it through AuditRecord, scoped to a user and limited,
     * as activity_show does.
     *
     * @return never
     */
    public function all(): array
    {
        throw new LogicException('AuditRecordWriter does not list every audit row. Query AuditRecord, scoped and limited.');
    }

    /**
     * The user the entry is for. account_delete is audited after the account is gone (ACC-005), so its row names
     * no user once that user no longer exists; its foreign key would refuse the row otherwise.
     *
     * @param  array<string, mixed>  $entry
     */
    private function userId(array $entry): ?int
    {
        $id = $entry['actor_id'] ?? null;
        $userId = ($entry['actor_type'] ?? null) === 'user' && is_string($id) && ctype_digit($id) ? (int) $id : null;

        if ($userId !== null && ($entry['capability_name'] ?? null) === 'account_delete' && ! User::query()->whereKey($userId)->exists()) {
            return null;
        }

        return $userId;
    }

    /**
     * Null for an event with no caller and for the server itself (a job or artisan). A
     * caller with no channel yet keeps its row with a null channel, so the write is still
     * audited, and the missing mapping is reported.
     */
    private function channel(?string $caller): ?string
    {
        if ($caller === null) {
            return null;
        }

        try {
            return Channel::derive($caller, $caller === 'http' ? $this->currentToken() : null)?->value;
        } catch (UnexpectedValueException $e) {
            report($e);

            return null;
        }
    }

    /** @param  array<string, mixed>  $entry */
    private function recordedAt(array $entry): CarbonImmutable
    {
        $at = $entry['recorded_at'] ?? null;

        return is_string($at) && $at !== '' ? CarbonImmutable::parse($at)->utc() : CarbonImmutable::now();
    }

    /** Over http the actor is the signed-in user; their token tells a script from the app. */
    private function currentToken(): ?object
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user->currentAccessToken() : null;
    }
}
