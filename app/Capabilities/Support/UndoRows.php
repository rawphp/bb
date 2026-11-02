<?php

namespace App\Capabilities\Support;

use App\Actions\Undo\ApplyUndo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reading and comparing the rows an undo token holds (CAP-013), shared by
 * {@see UndoLog::record()} and {@see ApplyUndo}. One instance serves one
 * record or apply, and keeps the foreign key map it loaded for that long.
 */
final class UndoRows
{
    /** @var array<string, array<string, list<array{table: string, columns: list<string>, parent_columns: list<string>}>>> each connection's foreign keys, by the table they point at */
    private array $foreignKeys = [];

    /**
     * The rows as the database holds them now, null for one that does not exist, by the
     * same keys as given. One query per table.
     *
     * @param  array<array-key, array{connection: ?string, table: string, key: string, id: int|string}>  $rows
     * @return array<array-key, array<string, mixed>|null>
     */
    public function load(array $rows, bool $lock): array
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
     * Whether two states of a row hold the same values. A state read back from
     * the JSON column may order its columns and type its numbers differently,
     * so columns are compared by name and values as text.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    public function same(?array $a, ?array $b): bool
    {
        return $this->text($a) === $this->text($b);
    }

    /**
     * The columns of an updated row that the write changed, as they were before it.
     * Only those are put back, so a column the write did not touch keeps its value.
     *
     * @param  array{before: array<string, mixed>, after: array<string, mixed>}  $row
     * @return array<string, mixed>
     */
    public function changedColumns(array $row): array
    {
        $before = $this->text($row['before']);
        $after = $this->text($row['after']);

        return array_filter($row['before'], fn (string $column) => ! array_key_exists($column, $after) || $after[$column] !== $before[$column], ARRAY_FILTER_USE_KEY);
    }

    /**
     * Whether any row, besides the given ones, points at one of these rows through a foreign key.
     * One query per table and foreign key, read with a lock so none is added meanwhile.
     *
     * @param  array<array-key, array{connection: ?string, table: string, key: string, id: int|string, after: ?array<string, mixed>}>  $rows
     * @param  array<array-key, array{connection: ?string, table: string, key: string, id: int|string}>  $besides
     */
    public function referenced(array $rows, array $besides = []): bool
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['connection'].':'.$row['table']][] = $row;
        }

        foreach ($groups as $group) {
            $connection = $group[0]['connection'];
            foreach ($this->foreignKeysOf($connection)[$group[0]['table']] ?? [] as $foreign) {
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
     * The connection's foreign keys, by the table they point at. They are read from the schema
     * once per migration and cached under the latest one, so a migration that adds a foreign key
     * is seen at once, and kept for the life of this instance.
     *
     * @return array<string, list<array{table: string, columns: list<string>, parent_columns: list<string>}>>
     */
    public function foreignKeysOf(?string $connection): array
    {
        $database = DB::connection($connection);

        return $this->foreignKeys[$database->getName()] ??= Cache::rememberForever(
            'undo-log:foreign-keys:'.$database->getName().':'.$database->table(config('database.migrations.table'))->max('id'),
            function () use ($database): array {
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
            },
        );
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
