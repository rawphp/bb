<?php

namespace App\Console\Commands;

use App\Actions\Foods\SaveImportedFoods;
use App\Domain\Foods\DumpRow;
use App\Domain\Foods\FoodSource;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

/**
 * Imports a downloaded food dump into the foods table at install time (FOOD-001); no dataset is in the repo.
 * Streams the file and saves batches of 1000 through SaveImportedFoods, so a re-run leaves identical
 * counts and never touches curation.
 *
 *   foods:import usda FoodData_Central_foundation_food_json.json
 *   foods:import usda FoodData_Central_branded_food_json.json
 *   foods:import off  openfoodfacts-products.jsonl.gz
 *
 * USDA FoodData Central JSON: the top-level key (FoundationFoods, SRLegacyFoods or BrandedFoods) picks
 * the type. Open Food Facts: JSONL, plain or .gz. How a record maps to a food: DumpRow. The command
 * fails when the file cannot be read, when every record was skipped, or when a JSONL line is not JSON:
 * a cut-off download ends mid-line (zlib reports a truncated .gz as a normal end of file), so the
 * install stops and can fetch the file again instead of carrying on with part of the database.
 *
 * @phpstan-import-type Row from DumpRow
 */
final class ImportFoods extends Command
{
    protected $signature = 'foods:import {source : usda or off} {path : Downloaded dump file}';

    protected $description = 'Import a USDA FoodData Central or Open Food Facts dump into the food database';

    private const BATCH = 1000;

    private int $imported = 0;

    private int $skipped = 0;

    private int $malformed = 0;

    private bool $readFailed = false;

    public function handle(SaveImportedFoods $save, DumpRow $rows): int
    {
        // The container keeps one command instance, so a second run in the same process starts from zero here.
        $this->imported = $this->skipped = $this->malformed = 0;
        $this->readFailed = false;
        $source = FoodSource::importable((string) $this->argument('source'));
        $path = (string) $this->argument('path');

        if ($source === null) {
            $this->error('Source must be usda or off.');

            return self::FAILURE;
        }
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found or not readable: {$path}");

            return self::FAILURE;
        }

        DB::disableQueryLog();
        if ($source === FoodSource::Usda) {
            $records = $this->usdaRows($rows, $path);
            if ($records === null) {
                $this->error('Not a Foundation, SR Legacy or Branded FoodData Central file.');

                return self::FAILURE;
            }
        } else {
            $handle = @fopen(str_ends_with($path, '.gz') ? 'compress.zlib://'.$path : $path, 'rb');
            if ($handle === false) {
                $this->error("Could not open {$path}.");

                return self::FAILURE;
            }
            $records = $this->offRows($rows, $handle);
        }

        $batch = [];
        foreach ($records as $row) {
            $batch[] = $row;
            if (count($batch) >= self::BATCH) {
                $this->flush($save, $batch);
                $batch = [];
            }
        }
        $this->flush($save, $batch);

        if ($this->readFailed || $this->malformed > 0) {
            $this->error("Imported {$this->imported} foods, but {$path} is truncated or corrupt"
                .($this->malformed > 0 ? " ({$this->malformed} lines are not valid JSON)" : '')
                .'. Download it again and re-run the import.');

            return self::FAILURE;
        }
        if ($this->imported === 0 && $this->skipped > 0) {
            $this->error("Imported 0 foods, skipped {$this->skipped}. Is this the right file for source {$source->value}?");

            return self::FAILURE;
        }

        $this->info("Imported {$this->imported} foods, skipped {$this->skipped}.");

        return self::SUCCESS;
    }

    /** @param  list<Row>  $batch */
    private function flush(SaveImportedFoods $save, array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $this->imported += $save->handle($batch);
    }

    /** @return Generator<int, Row>|null */
    private function usdaRows(DumpRow $rows, string $path): ?Generator
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        if (! preg_match('/"(FoundationFoods|SRLegacyFoods|BrandedFoods)"\s*:/', $head, $m)) {
            return null;
        }
        $key = $m[1];

        return (function () use ($rows, $path, $key) {
            $items = Items::fromFile($path, ['pointer' => '/'.$key, 'decoder' => new ExtJsonDecoder(true)]);
            foreach ($items as $item) {
                $row = is_array($item) ? $rows->fromUsda($item, $key === 'BrandedFoods') : null;
                if ($row === null) {
                    $this->skipped++;

                    continue;
                }
                yield $row;
            }
        })();
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, Row>
     */
    private function offRows(DumpRow $rows, $handle): Generator
    {
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $product = json_decode($line, true);
                if (! is_array($product)) {
                    $this->malformed++;

                    continue;
                }
                $row = $rows->fromOpenFoodFacts($product);
                if ($row === null) {
                    $this->skipped++;

                    continue;
                }
                yield $row;
            }
            $this->readFailed = ! feof($handle);
        } finally {
            fclose($handle);
        }
    }
}
