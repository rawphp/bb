<?php

namespace App\Actions\Undo;

use App\Capabilities\Support\UndoRows;
use App\Capabilities\Support\WriteResult;
use App\Models\UndoToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Rawphp\Capabilities\Support\CapabilityFailure;

/**
 * Revert the write behind one of the user's undo tokens, once (CAP-013). It is refused when
 * the token is 24 hours old, when a later write has changed one of its rows, when a row added
 * since points at a row it would remove, or when a row added since holds a key one it puts back
 * needs. Nothing is changed then.
 */
final class ApplyUndo
{
    private const CHANGED = 'This can\'t be undone because it has changed since.';

    private UndoRows $rows;

    public function __invoke(User $user, string $token): WriteResult
    {
        $this->rows = new UndoRows;

        return DB::transaction(function () use ($user, $token): WriteResult {
            $undo = UndoToken::query()->whereBelongsTo($user)->whereKey($token)->lockForUpdate()->firstOrFail();

            if ($undo->expires_at->lessThanOrEqualTo(now())) {
                throw CapabilityFailure::conflict('This can no longer be undone. Undo lasts '.UndoToken::LIFETIME_HOURS.' hours.');
            }

            // Locking reads: the rows compared are the rows restored, and no other write slips between.
            $changes = $undo->changes;
            $now = $this->rows->load($changes, lock: true);
            foreach ($changes as $ref => $row) {
                if (! $this->rows->same($row['after'], $now[$ref])) {
                    throw CapabilityFailure::conflict(self::CHANGED);
                }
            }

            // A row the write created goes only if nothing outside the write now points at it.
            $removed = array_filter($changes, fn (array $row) => $row['before'] === null);
            if ($this->rows->referenced($removed, besides: $changes)) {
                throw CapabilityFailure::conflict('This can\'t be undone because something added since depends on it.');
            }

            $this->restore(array_reverse($changes));

            $undo->delete();

            return new WriteResult(invalidates: $undo->invalidates, toast: 'Undone.');
        });
    }

    /**
     * Put every row back as it was before the write. The order that keeps keys valid depends
     * on the write: a created row may hold the unique key of a row it replaced, and a deleted
     * row may be the parent of an updated one. So a step that breaks a constraint is rolled back
     * and tried again after the others, and a created row is removed only once no updated row
     * still points at it, so its foreign keys never cascade. When a pass puts nothing back, a
     * row added since holds a key one of these needs.
     *
     * @param  list<array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>, after: ?array<string, mixed>}>  $rows  latest change first
     */
    private function restore(array $rows): void
    {
        // Created rows first, keeping latest first, so a write's own children go before their parents.
        usort($rows, fn (array $a, array $b) => ($b['before'] === null) <=> ($a['before'] === null));

        while ($rows !== []) {
            $left = [];
            foreach ($rows as $index => $row) {
                $updates = array_filter(
                    [...$left, ...array_slice($rows, $index + 1)],
                    fn (array $other) => $other['before'] !== null && $other['after'] !== null,
                );
                if (($row['before'] === null && $this->pointsAt($updates, $row)) || ! $this->tryRestore($row)) {
                    $left[] = $row;
                }
            }

            if (count($left) === count($rows)) {
                throw CapabilityFailure::conflict(self::CHANGED);
            }
            $rows = $left;
        }
    }

    /**
     * Put one row back, in a savepoint. False when it breaks an integrity constraint
     * (SQLSTATE class 23), which the savepoint has rolled back.
     *
     * @param  array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>, after: ?array<string, mixed>}  $row
     */
    private function tryRestore(array $row): bool
    {
        $database = DB::connection($row['connection']);

        try {
            $database->transaction(function () use ($database, $row): void {
                $table = $database->table($row['table']);
                match (true) {
                    $row['before'] === null => $table->where($row['key'], $row['id'])->delete(),
                    $row['after'] === null => $table->insert($row['before']),
                    default => $table->where($row['key'], $row['id'])->update($this->rows->changedColumns($row)),
                };
            });
        } catch (QueryException $failed) {
            if (! str_starts_with((string) $failed->getCode(), '23')) {
                throw $failed;
            }

            return false;
        }

        return true;
    }

    /**
     * Whether one of these rows, as it is now, points at the given row through a foreign key.
     *
     * @param  array<array-key, array{connection: ?string, table: string, after: ?array<string, mixed>}>  $rows
     * @param  array{connection: ?string, table: string, after: ?array<string, mixed>}  $row
     */
    private function pointsAt(array $rows, array $row): bool
    {
        foreach ($this->rows->foreignKeysOf($row['connection'])[$row['table']] ?? [] as $foreign) {
            foreach ($rows as $other) {
                if ($other['connection'] !== $row['connection'] || $other['table'] !== $foreign['table']) {
                    continue;
                }
                $matches = true;
                foreach ($foreign['columns'] as $index => $column) {
                    $value = $other['after'][$column] ?? null;
                    $matches = $matches && $value !== null && (string) $value === (string) ($row['after'][$foreign['parent_columns'][$index]] ?? '');
                }
                if ($matches) {
                    return true;
                }
            }
        }

        return false;
    }
}
