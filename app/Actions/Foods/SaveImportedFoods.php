<?php

namespace App\Actions\Foods;

use App\Domain\Foods\DumpRow;
use App\Models\Food;
use App\Models\FoodServing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one write path for imported foods (FOOD-001): upsert on (source, source_id), then replace each
 * food's servings, all in one transaction. A repeated source_id in one batch keeps the last row. Source ids
 * compare exactly, so codes that differ only by case or accent are different foods.
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
     * @return array{saved: int, merged: int} foods saved; rows that repeated another row's source id in the batch
     */
    public function __invoke(array $rows, array $updateColumns = self::UPDATABLE): array
    {
        $bySource = [];
        foreach ($rows as $row) {
            // Prefixed keys keep numeric ids strings: PHP would turn '123' into an int key.
            $bySource[(string) $row['food']['source']]['s'.$row['food']['source_id']] = $row;
        }
        $saved = array_sum(array_map('count', $bySource));

        DB::transaction(function () use ($bySource, $updateColumns) {
            $now = now();

            foreach ($bySource as $source => $batch) {
                $batch = array_values($batch);
                Food::query()->upsert(
                    array_map(fn (array $r) => ['id' => (string) Str::ulid()] + $r['food'] + ['created_at' => $now, 'updated_at' => $now], $batch),
                    ['source', 'source_id'],
                    $updateColumns,
                );

                // source_id compares exactly (a binary collation on MySQL), so each row finds its own food.
                // withTrashed: the upsert also updates soft-deleted rows, so their ids must be found too.
                $sourceIds = array_map(fn (array $r) => (string) $r['food']['source_id'], $batch);
                $ids = Food::withTrashed()->where('source', $source)->whereIn('source_id', $sourceIds)->pluck('id', 'source_id');
                FoodServing::query()->whereIn('food_id', $ids->values())->delete();

                $servings = [];
                foreach ($batch as $r) {
                    foreach ($r['servings'] as $serving) {
                        $servings[] = $serving + ['food_id' => $ids[(string) $r['food']['source_id']]];
                    }
                }
                foreach (array_chunk($servings, 1000) as $chunk) {
                    FoodServing::query()->insert($chunk);
                }
            }
        });

        return ['saved' => $saved, 'merged' => count($rows) - $saved];
    }
}
