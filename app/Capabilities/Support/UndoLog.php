<?php

namespace App\Capabilities\Support;

use App\Models\UndoToken;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Rawphp\Capabilities\Support\CapabilityFailure;

/**
 * Undo for reversible writes (CAP-013).
 *
 * {@see record()} runs a write and watches every Eloquent model it creates,
 * updates or deletes, including the automatic changes it makes (re-targeted
 * meals, added sets). It stores each changed row's state before and after
 * under a new undo token. {@see apply()} puts the rows back, unless one of
 * them no longer matches its state after the write: then a later write has
 * touched it, and undo is refused.
 *
 * Only changes made through Eloquent models are seen. A reversible write must
 * not change rows with the query builder, a mass update or a cascading delete.
 */
#[Singleton]
final class UndoLog
{
    public const LIFETIME_HOURS = 24;

    /** @var array<string, array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>}>|null the rows seen while recording, by table and key */
    private ?array $seen = null;

    public function __construct(Dispatcher $events)
    {
        $events->listen('eloquent.created: *', fn (string $event, array $payload) => $this->see($payload[0], created: true));
        $events->listen(['eloquent.updating: *', 'eloquent.deleting: *'], fn (string $event, array $payload) => $this->see($payload[0], created: false));
    }

    /**
     * Run a reversible write and give its result an undo token. A write that
     * changes nothing gets none. A write recorded inside another shares its token.
     *
     * @param  Closure(): WriteResult  $write
     */
    public function record(User $user, Closure $write): WriteResult
    {
        if ($this->seen !== null) {
            return $write();
        }

        return DB::transaction(function () use ($user, $write): WriteResult {
            $this->seen = [];
            try {
                $result = $write();
                $seen = $this->seen;
            } finally {
                $this->seen = null;
            }

            $changes = [];
            foreach ($seen as $row) {
                $after = $this->current($row);
                if (! $this->same($row['before'], $after)) {
                    $changes[] = [...$row, 'after' => $after];
                }
            }

            if ($changes === []) {
                return $result;
            }

            UndoToken::query()->whereBelongsTo($user)->where('expires_at', '<=', now())->delete();

            $token = new UndoToken([
                'changes' => $changes,
                'invalidates' => $result->invalidates,
                'expires_at' => now()->addHours(self::LIFETIME_HOURS),
            ]);
            $token->user()->associate($user)->save();

            return new WriteResult(invalidates: $result->invalidates, toast: $result->toast, undo: $token->id);
        });
    }

    /** Revert the write behind the user's token, once. */
    public function apply(User $user, string $token): WriteResult
    {
        return DB::transaction(function () use ($user, $token): WriteResult {
            $undo = UndoToken::query()->whereBelongsTo($user)->whereKey($token)->lockForUpdate()->firstOrFail();

            if ($undo->expires_at->lessThanOrEqualTo(now())) {
                throw CapabilityFailure::conflict('This can no longer be undone. Undo lasts '.self::LIFETIME_HOURS.' hours.');
            }

            foreach ($undo->changes as $row) {
                if (! $this->same($row['after'], $this->current($row))) {
                    throw CapabilityFailure::conflict('This can\'t be undone because it has changed since.');
                }
            }

            foreach (array_reverse($undo->changes) as $row) {
                $this->restore($row);
            }

            $undo->delete();

            return new WriteResult(invalidates: $undo->invalidates, toast: 'Undone.');
        });
    }

    private function see(Model $model, bool $created): void
    {
        if ($this->seen === null) {
            return;
        }

        $row = [
            'connection' => $model->getConnectionName(),
            'table' => $model->getTable(),
            'key' => $model->getKeyName(),
            'id' => $model->getKey(),
        ];
        $ref = $row['table'].':'.$row['id'];

        // The first sighting holds the state before the write.
        $this->seen[$ref] ??= [...$row, 'before' => $created ? null : $this->current($row)];
    }

    /**
     * The row as the database holds it now, or null when it does not exist.
     *
     * @param  array{connection: ?string, table: string, key: string, id: int|string}  $row
     * @return array<string, mixed>|null
     */
    private function current(array $row): ?array
    {
        $found = DB::connection($row['connection'])->table($row['table'])->where($row['key'], $row['id'])->first();

        return $found === null ? null : (array) $found;
    }

    /** @param array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>} $row */
    private function restore(array $row): void
    {
        $table = DB::connection($row['connection'])->table($row['table']);

        if ($row['before'] === null) {
            $table->where($row['key'], $row['id'])->delete();
        } else {
            $table->updateOrInsert([$row['key'] => $row['id']], $row['before']);
        }
    }

    /**
     * Whether two states of a row hold the same values. A state read back from
     * the JSON column may order its columns and type its numbers differently,
     * so columns are compared by name and values as text.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    private function same(?array $a, ?array $b): bool
    {
        return $this->text($a) === $this->text($b);
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return array<string, string|null>|null
     */
    private function text(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        ksort($row);

        return array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $row);
    }
}
