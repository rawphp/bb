<?php

namespace App\Actions\Foods;

use App\Domain\Foods\DumpRow;
use App\Models\Food;
use App\Models\FoodServing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Normalizer;

/**
 * The one write path for imported foods (FOOD-001): upsert on (source, source_id), then replace each
 * food's servings, all in one transaction. A repeated source_id in one batch keeps the last row; ids that
 * differ only by case or accent count as one, as the unique index's collation does.
 * An existing row keeps its id and its curation (FOOD-002); only the columns below are refreshed.
 * A soft-deleted row stays deleted: the import refreshes its values and servings but does not restore it.
 *
 * @phpstan-import-type Row from DumpRow
 */
final class SaveImportedFoods
{
    public const UPDATABLE = ['name', 'brand', 'barcode', 'kind', 'kcal', 'protein', 'fat', 'carbs', 'fiber',
        ...Food::DIETS, 'updated_at'];

    /**
     * @param  list<Row>  $rows
     * @param  list<string>  $updateColumns  food columns refreshed when the row already exists
     * @return array{saved: int, merged: int, unmatched: int} foods saved; rows merged into another row with the
     *                                                        same source id; rows whose servings were dropped because no saved food matched them
     */
    public function __invoke(array $rows, array $updateColumns = self::UPDATABLE): array
    {
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(string) $row['food']['source']][self::key((string) $row['food']['source_id'])] = $row;
        }
        $saved = array_sum(array_map('count', $bySource));
        $unmatched = 0;

        DB::transaction(function () use ($bySource, $updateColumns, &$unmatched) {
            $now = now();

            foreach ($bySource as $source => $batch) {
                $batch = array_values($batch);
                $sourceIds = array_map(fn (array $r) => (string) $r['food']['source_id'], $batch);
                // withTrashed: the upsert also updates soft-deleted rows, so they are found and kept too.
                $existing = fn () => Food::withTrashed()->where('source', $source)->whereIn('source_id', $sourceIds);
                $before = $existing()->get(['id', 'source_id', ...$updateColumns]);
                $upsert = fn (array $rows) => Food::query()->upsert(
                    array_map(fn (array $r) => ['id' => (string) Str::ulid()] + $r['food'] + ['created_at' => $now, 'updated_at' => $now], $rows),
                    ['source', 'source_id'],
                    $updateColumns,
                );
                $upsert($batch);

                $ids = [];
                foreach ($existing()->pluck('id', 'source_id') as $sourceId => $id) {
                    $ids[self::key((string) $sourceId)] = $id;
                }

                // A row the collation folds onto another food (STRAßE1 onto STRASSE1) but key() does not has just
                // overwritten that food. Put it back: re-save this batch's own rows, and restore any earlier food
                // no row of this batch names, from its values before the upsert.
                $folded = array_filter($batch, fn (array $r) => ! isset($ids[self::key((string) $r['food']['source_id'])]));
                if ($folded !== []) {
                    $upsert(array_values(array_filter($batch, fn (array $r) => isset($ids[self::key((string) $r['food']['source_id'])]))));
                    foreach ($before as $food) {
                        if (! isset($bySource[$source][self::key((string) $food->source_id)])) {
                            Food::withTrashed()->whereKey($food->id)->toBase()->update(array_intersect_key($food->getAttributes(), array_flip($updateColumns)));
                        }
                    }
                }

                $servings = [];
                $matched = [];
                foreach ($batch as $r) {
                    $id = $ids[self::key((string) $r['food']['source_id'])] ?? null;
                    if ($id === null) {
                        $unmatched++;
                        Log::warning('foods:import skipped a row the database matched to another food; that food was left as it was', ['source' => $source, 'source_id' => $r['food']['source_id']]);

                        continue;
                    }
                    $matched[] = $id;
                    foreach ($r['servings'] as $serving) {
                        $servings[] = $serving + ['food_id' => $id];
                    }
                }
                // Only the foods a row matched get new servings, so only theirs are replaced: a food the database
                // folded an unmatched row onto keeps its servings, as it kept its values above.
                FoodServing::query()->whereIn('food_id', $matched)->delete();
                foreach (array_chunk($servings, 1000) as $chunk) {
                    FoodServing::query()->insert($chunk);
                }
            }
        });

        // An unmatched row was folded into another food by the database, so it is not a food of its own.
        return ['saved' => $saved - $unmatched, 'merged' => count($rows) - $saved, 'unmatched' => $unmatched];
    }

    /**
     * One key per row the unique (source, source_id) index sees as one: the column's collation ignores
     * case and accents, so "ABC1", "abc1" and "ÀBC1" are the same food. Accents are folded by stripping
     * combining marks, so a character with no ASCII form ("食1") stays distinct. Prefixed so PHP keeps
     * "123" a string key.
     */
    private static function key(string $sourceId): string
    {
        $decomposed = Normalizer::normalize($sourceId, Normalizer::FORM_D);

        return 's'.mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $sourceId : $decomposed));
    }
}
