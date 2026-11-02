<?php

namespace App\Actions\Foods;

use App\Domain\Foods\DumpRow;
use App\Models\Food;
use App\Models\FoodServing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * @return int the foods saved, each repeated source_id once
     */
    public function __invoke(array $rows, array $updateColumns = self::UPDATABLE): int
    {
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(string) $row['food']['source']][self::key((string) $row['food']['source_id'])] = $row;
        }

        DB::transaction(function () use ($bySource, $updateColumns) {
            $now = now();

            foreach ($bySource as $source => $batch) {
                $batch = array_values($batch);
                Food::query()->upsert(
                    array_map(fn (array $r) => ['id' => (string) Str::ulid()] + $r['food'] + ['created_at' => $now, 'updated_at' => $now], $batch),
                    ['source', 'source_id'],
                    $updateColumns,
                );

                $sourceIds = array_map(fn (array $r) => (string) $r['food']['source_id'], $batch);
                // withTrashed: the upsert also updates soft-deleted rows, so their ids must be found too.
                $ids = [];
                foreach (Food::withTrashed()->where('source', $source)->whereIn('source_id', $sourceIds)->pluck('id', 'source_id') as $sourceId => $id) {
                    $ids[self::key((string) $sourceId)] = $id;
                }
                FoodServing::query()->whereIn('food_id', array_values($ids))->delete();

                $servings = [];
                foreach ($batch as $r) {
                    $id = $ids[self::key((string) $r['food']['source_id'])] ?? null;
                    foreach ($id === null ? [] : $r['servings'] as $serving) {
                        $servings[] = $serving + ['food_id' => $id];
                    }
                }
                foreach (array_chunk($servings, 1000) as $chunk) {
                    FoodServing::query()->insert($chunk);
                }
            }
        });

        return array_sum(array_map('count', $bySource));
    }

    /**
     * One key per row the unique (source, source_id) index sees as one: the column's collation ignores
     * case and accents, so "ABC1" and "abc1" are the same food. Prefixed so PHP keeps "123" a string key.
     */
    private static function key(string $sourceId): string
    {
        return 's'.mb_strtolower(Str::ascii($sourceId));
    }
}
