<?php

namespace App\Capabilities\Support;

use App\Actions\Undo\ApplyUndo;
use App\Models\UndoToken;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Undo for reversible writes (CAP-013).
 *
 * {@see record()} runs a write and watches every Eloquent model it creates,
 * updates or deletes, including the automatic changes it makes through models.
 * It stores each changed row's state before and after under a new undo token,
 * which {@see ApplyUndo} reverts.
 *
 * Only changes made through Eloquent models are seen, so a reversible write
 * that changes rows any other way fails instead of returning a token: a query
 * builder or mass write, or a delete that would cascade to rows the write did
 * not delete first.
 */
#[Singleton]
final class UndoLog
{
    /** @var array<string, array{connection: ?string, table: string, key: string, id: int|string, before: ?array<string, mixed>}>|null the rows seen while recording, by table and key */
    private ?array $seen = null;

    /** Write statements run while recording, and how many of them Eloquent models ran. */
    private int $statements = 0;

    private int $modelStatements = 0;

    /** @var list<string> the read keys of the writes recorded inside the one being recorded */
    private array $nestedInvalidates = [];

    /** The rows helper for the write being recorded, with its foreign key map. */
    private UndoRows $rows;

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
            if ($this->seen !== null && $this->writesUserData($query)) {
                $this->statements++;
            }
        });
    }

    /**
     * Run a reversible write and give its result an undo token. A write that
     * changes nothing gets none. A write recorded inside another shares its token, which
     * then names both writes' reads.
     *
     * @param  Closure(): WriteResult  $write
     */
    public function record(User $user, Closure $write): WriteResult
    {
        if ($this->seen !== null) {
            $result = $write();
            // The outer write's token undoes this one's rows too, so undo must name its reads.
            $this->nestedInvalidates = [...$this->nestedInvalidates, ...$result->invalidates];

            return $result;
        }

        return DB::transaction(function () use ($user, $write): WriteResult {
            // Before the window opens: reading the map may write to a database cache store.
            $this->rows = new UndoRows;
            $this->rows->foreignKeysOf(null);

            $this->seen = [];
            $this->statements = $this->modelStatements = 0;
            $this->nestedInvalidates = [];
            try {
                $result = $write();
                $invalidates = array_values(array_unique([...$result->invalidates, ...$this->nestedInvalidates]));
                if ($this->statements !== $this->modelStatements) {
                    throw new LogicException('A reversible write changed rows without an Eloquent model, so undo could not put them back.');
                }
                $seen = $this->seen;
            } finally {
                $this->seen = null;
                $this->nestedInvalidates = [];
            }

            $now = $this->rows->load($seen, lock: false);
            $changes = [];
            foreach ($seen as $ref => $row) {
                if (! $this->rows->same($row['before'], $now[$ref])) {
                    $changes[] = [...$row, 'after' => $now[$ref]];
                }
            }

            if ($changes === []) {
                return new WriteResult(invalidates: $invalidates, toast: $result->toast);
            }

            $token = new UndoToken([
                'changes' => $changes,
                'invalidates' => $invalidates,
                'expires_at' => now()->addHours(UndoToken::LIFETIME_HOURS),
            ]);
            $token->user()->associate($user)->save();

            return new WriteResult(invalidates: $invalidates, toast: $result->toast, undo: $token->id);
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
        $this->seen[$ref] ??= [...$row, 'before' => $created ? null : $this->rows->load([$row], lock: true)[0]];
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
        if ($this->rows->referenced([$row])) {
            throw new LogicException("A reversible write deleted a {$row['table']} row that other rows still point at. Delete those through their models first, or undo could not put them back.");
        }
    }

    /**
     * Whether a statement writes rows of the app's data. The framework's own tables (cache,
     * queue, sessions) are written outside models by design, and undo leaves them alone.
     */
    private function writesUserData(QueryExecuted $query): bool
    {
        if (preg_match('/^\s*(insert|update|delete|replace|upsert|merge|truncate)\b/i', $query->sql) !== 1) {
            return false;
        }
        if (preg_match('/^\s*(?:insert\s+(?:ignore\s+)?into|replace\s+into|update|delete\s+from|truncate(?:\s+table)?)\s+[`"]?([\w.]+)/i', $query->sql, $match) !== 1) {
            return true;
        }

        $framework = array_filter([
            config('cache.stores.database.table'),
            config('cache.stores.database.lock_table'),
            config('queue.connections.database.table'),
            config('queue.batching.table'),
            config('queue.failed.table'),
            config('session.table'),
        ]);

        return ! in_array(substr($match[1], strlen($query->connection->getTablePrefix())), $framework, true);
    }
}
