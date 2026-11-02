<?php

namespace App\Capabilities\Support;

use App\Models\AuditRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Laravel\Sanctum\Contracts\HasApiTokens;
use LogicException;
use Rawphp\Capabilities\Contracts\AuditWriter;

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
 * is dated by it.
 */
final class AuditRecordWriter implements AuditWriter
{
    public function __construct(private readonly Auth $auth) {}

    public function write(array $entry): void
    {
        // Approval and tool-boundary events carry no caller, so they have no channel.
        $caller = is_string($entry['caller'] ?? null) ? $entry['caller'] : null;

        AuditRecord::create([
            'user_id' => $this->userId($entry),
            'event' => (string) ($entry['event'] ?? 'capability.unknown'),
            'capability' => $entry['capability_name'] ?? null,
            'channel' => $caller === null ? null : Channel::derive($caller, $caller === 'http' ? $this->currentToken() : null)->value,
            'input' => $entry['redacted_input'] ?? null,
            'result' => $entry['result'] ?? null,
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

    /** @param  array<string, mixed>  $entry */
    private function userId(array $entry): ?int
    {
        $id = $entry['actor_id'] ?? null;

        return ($entry['actor_type'] ?? null) === 'user' && is_string($id) && ctype_digit($id) ? (int) $id : null;
    }

    /** @param  array<string, mixed>  $entry */
    private function recordedAt(array $entry): CarbonImmutable
    {
        $at = $entry['recorded_at'] ?? null;

        return is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : CarbonImmutable::now();
    }

    /** Over http the actor is the signed-in user; their token tells a script from the app. */
    private function currentToken(): ?object
    {
        $user = $this->auth->guard()->user();

        return $user instanceof HasApiTokens ? $user->currentAccessToken() : null;
    }
}
