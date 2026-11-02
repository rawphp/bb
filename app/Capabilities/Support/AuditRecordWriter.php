<?php

namespace App\Capabilities\Support;

use App\Models\AuditRecord;
use Illuminate\Contracts\Auth\Factory as Auth;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Rawphp\Capabilities\Contracts\AuditWriter;

/**
 * The package's audit sink (D-010), bound by the host: one audit_records row
 * per entry, with the user and the channel (CAP-022). The package redacts the
 * input before the entry reaches here. Reads are not audited.
 */
final class AuditRecordWriter implements AuditWriter
{
    public function __construct(private readonly Auth $auth) {}

    public function write(array $entry): void
    {
        $caller = is_string($entry['caller'] ?? null) ? $entry['caller'] : 'http';

        AuditRecord::create([
            'user_id' => $this->userId($entry),
            'event' => (string) ($entry['event'] ?? 'capability.unknown'),
            'capability' => $entry['capability_name'] ?? null,
            'channel' => Channel::derive($caller, $caller === 'http' ? $this->currentToken() : null)->value,
            'input' => $entry['redacted_input'] ?? null,
            'result' => $entry['result'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return AuditRecord::query()->oldest()->oldest('id')->get()
            ->map(fn (AuditRecord $record): array => [
                'event' => $record->event,
                'capability_name' => $record->capability,
                'actor_type' => $record->user_id === null ? null : 'user',
                'actor_id' => $record->user_id === null ? null : (string) $record->user_id,
                'payload' => ['channel' => $record->channel, 'input' => $record->input, 'result' => $record->result],
                'recorded_at' => $record->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $entry */
    private function userId(array $entry): ?int
    {
        $id = $entry['actor_id'] ?? null;

        return ($entry['actor_type'] ?? null) === 'user' && is_string($id) && ctype_digit($id) ? (int) $id : null;
    }

    /** Over http the actor is the signed-in user; their token tells a script from the app. */
    private function currentToken(): ?object
    {
        $user = $this->auth->guard()->user();

        return $user instanceof HasApiTokens ? $user->currentAccessToken() : null;
    }
}
