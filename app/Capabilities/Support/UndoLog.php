<?php

namespace App\Capabilities\Support;

use App\Models\UndoToken;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Rawphp\Capabilities\Support\CapabilityFailure;

/**
 * Undo for reversible writes (CAP-013).
 *
 * {@see record()} runs a write and watches every Eloquent model it creates,
 * updates or deletes, including the automatic changes it makes through models.
 * It stores each changed row's state before and after under a new undo token.
 * {@see apply()} puts the rows back, unless a later write has changed one of
 * them, now holds its unique key, or points at a row undo would remove: then
 * undo is refused.
 *
 * Only changes made through Eloquent models are seen, so a reversible write
 * that changes rows any other way fails instead of returning a token: a query
 * builder or mass write (DayTarget's meal index, for one), or a delete that
 * would cascade to rows the write did not delete first.
 */
#[Singleton]
final class UndoLog
{
    public const LIFETIME_HOURS = 24;

    private const CHANGED = 'This can\'t be undone because it has changed since.';

    /** @var array<string, array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>}>|null the rows seen while recording, by table and key */
    private ?array $seen = null;

    /** Write statements run while recording, and how many of them Eloquent models ran. */
    private int $statements = 0;

    private int $modelStatements = 0;

    public function __construct(Dispatcher $events)
    {
        $events->listen('eloquent.created: *', fn (string $event, array $payload) => $this->see($payload[0], created: true));
        $events->listen('eloquent.updating: *', fn (string $event, array $payload) => $this->see($payload[0], created: false));
        $events->listen('eloquent.deleting: *', function (string $event, array $payload): void {
            $this->see($payload[0], created: false);
            $this->refuseCascade($payload[0]);
        });
        $events->listen(['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'], function (): void {
            if ($this->seen !== null) {
                $this->modelStatements++;
            }
        });
        $events->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if ($this->seen !== null && preg_match('/^\s*(insert|update|delete|replace|upsert|merge|truncate)\b/i', $query->sql) === 1) {
                $this->statements++;
            }
        });
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
            $this->statements = $this->modelStatements = 0;
            try {
                $result = $write();
                if ($this->statements !== $this->modelStatements) {
                    throw new LogicException('A reversible write changed rows without an Eloquent model, so undo could not put them back.');
                }
                $seen = $this->seen;
            } finally {
                $this->seen = null;
            }

            $now = $this->load($seen, lock: false);
            $changes = [];
            foreach ($seen as $ref => $row) {
                if (! $this->same($row['before'], $now[$ref])) {
                    $changes[] = [...$row, 'after' => $now[$ref]];
                }
            }

            if ($changes === []) {
                return $result;
            }

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

            // Locking reads: the rows compared are the rows restored, and no other write slips between.
            $changes = $undo->changes;
            $now = $this->load($changes, lock: true);
            foreach ($changes as $ref => $row) {
                if (! $this->same($row['after'], $now[$ref])) {
                    throw CapabilityFailure::conflict(self::CHANGED);
                }
            }

            // Deleted rows come back before the rows that point at them, and created rows go
            // after the rows that pointed at them have been put back, each in reverse order.
            $reversed = array_reverse($changes);
            $putBack = array_filter($reversed, fn (array $row) => $row['after'] === null);
            $updated = array_filter($reversed, fn (array $row) => $row['before'] !== null && $row['after'] !== null);
            $removed = array_filter($reversed, fn (array $row) => $row['before'] === null);

            // A row the write created goes only if nothing outside the write now points at it.
            if ($this->referenced($removed, besides: $changes)) {
                throw CapabilityFailure::conflict('This can\'t be undone because something added since depends on it.');
            }

            try {
                foreach ($putBack as $row) {
                    DB::connection($row['connection'])->table($row['table'])->insert($row['before']);
                }
                foreach ($updated as $row) {
                    DB::connection($row['connection'])->table($row['table'])->where($row['key'], $row['id'])->update($this->changedColumns($row));
                }
                foreach ($removed as $row) {
                    DB::connection($row['connection'])->table($row['table'])->where($row['key'], $row['id'])->delete();
                }
            } catch (QueryException $failed) {
                // An integrity constraint (SQLSTATE class 23): a row added since holds a unique key of one put back.
                if (! str_starts_with((string) $failed->getCode(), '23')) {
                    throw $failed;
                }

                throw CapabilityFailure::conflict(self::CHANGED);
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

        // The first sighting holds the state before the write, read from the database with a
        // lock: the model may have been loaded before the write and be out of date.
        $this->seen[$ref] ??= [...$row, 'before' => $created ? null : $this->load([$row], lock: true)[0]];
    }

    /**
     * Refuse a delete that would cascade past what undo sees: a row still pointed at is
     * deleted only after the rows pointing at it, through their models. A soft delete keeps them.
     */
    private function refuseCascade(Model $model): void
    {
        if ($this->seen === null || (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting())) {
            return;
        }

        $row = ['connection' => $model->getConnectionName(), 'table' => $model->getTable(), 'key' => $model->getKeyName(), 'id' => $model->getKey(), 'after' => $model->getRawOriginal()];
        if ($this->referenced([$row])) {
            throw new LogicException("A reversible write deleted a {$row['table']} row that other rows still point at. Delete those through their models first, or undo could not put them back.");
        }
    }

    /**
     * Whether any row, besides the given ones, points at one of these rows through a foreign key.
     * One query per table and foreign key, read with a lock so none is added meanwhile.
     *
     * @param  array<array-key, array{connection: ?string, table: string, key: string, id: int|string, after: ?array<string, mixed>}>  $rows
     * @param  array<array-key, array{connection: ?string, table: string, key: string, id: int|string}>  $besides
     */
    private function referenced(array $rows, array $besides = []): bool
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['connection'].':'.$row['table']][] = $row;
        }

        foreach ($groups as $group) {
            $connection = $group[0]['connection'];
            foreach ($this->referencesTo($connection, $group[0]['table']) as $foreign) {
                $query = DB::connection($connection)->table($foreign['table'])->sharedLock()
                    ->where(function ($query) use ($group, $foreign): void {
                        foreach ($group as $row) {
                            $query->orWhere(function ($query) use ($row, $foreign): void {
                                foreach ($foreign['columns'] as $index => $column) {
                                    $query->where($column, $row['after'][$foreign['parent_columns'][$index]] ?? null);
                                }
                            });
                        }
                    });
                $others = array_filter($besides, fn (array $other) => $other['connection'] === $connection && $other['table'] === $foreign['table']);
                if ($others !== []) {
                    $query->whereNotIn(reset($others)['key'], array_column($others, 'id'));
                }
                if ($query->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The foreign keys that point at a table. They are read from the schema once per migration
     * and cached under the latest one, so a migration that adds a foreign key is seen at once.
     *
     * @return list<array{table: string, columns: list<string>, parent_columns: list<string>}>
     */
    private function referencesTo(?string $connection, string $table): array
    {
        $database = DB::connection($connection);
        $version = $database->getName().':'.$database->table(config('database.migrations.table'))->max('id');

        $references = Cache::rememberForever('undo-log:foreign-keys:'.$version, function () use ($database): array {
            $schema = $database->getSchemaBuilder();
            $references = [];
            foreach ($schema->getTableListing(schemaQualified: false) as $child) {
                foreach ($schema->getForeignKeys($child) as $foreign) {
                    $references[$foreign['foreign_table']][] = [
                        'table' => $child,
                        'columns' => $foreign['columns'],
                        'parent_columns' => $foreign['foreign_columns'],
                    ];
                }
            }

            return $references;
        });

        return $references[$table] ?? [];
    }

    /**
     * The rows as the database holds them now, null for one that does not exist, by the
     * same keys as given. One query per table.
     *
     * @param  array<array-key, array{connection: ?string, table: string, key: string, id: int|string}>  $rows
     * @return array<array-key, array<string, mixed>|null>
     */
    private function load(array $rows, bool $lock): array
    {
        $groups = [];
        foreach ($rows as $ref => $row) {
            $groups[$row['connection'].':'.$row['table'].':'.$row['key']][$ref] = $row;
        }

        $loaded = [];
        foreach ($groups as $group) {
            $first = reset($group);
            $query = DB::connection($first['connection'])->table($first['table'])->whereIn($first['key'], array_column($group, 'id'));
            $found = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy($first['key']);
            foreach ($group as $ref => $row) {
                $match = $found->get($row['id']);
                $loaded[$ref] = $match === null ? null : (array) $match;
            }
        }

        return $loaded;
    }

    /**
     * The columns of an updated row that the write changed, as they were before it.
     * Only those are put back, so a column the write did not touch keeps its value.
     *
     * @param  array{before: array<string, mixed>, after: array<string, mixed>}  $row
     * @return array<string, mixed>
     */
    private function changedColumns(array $row): array
    {
        $before = $this->text($row['before']);
        $after = $this->text($row['after']);

        return array_filter($row['before'], fn (string $column) => ! array_key_exists($column, $after) || $after[$column] !== $before[$column], ARRAY_FILTER_USE_KEY);
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
