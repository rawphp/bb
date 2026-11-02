<?php

namespace App\Console\Commands;

use App\Actions\Foods\SaveImportedFoods;
use App\Domain\Foods\DumpRow;
use App\Domain\Foods\FoodSource;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonMachine\Exception\JsonMachineException;
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
 * fails when the file cannot be read, when it imports no food (an empty or wrong file), or when the
 * file is cut off or corrupt: a USDA file whose JSON does not parse, or a JSONL line that is not JSON
 * (a cut-off download usually ends mid-line), or a .gz whose gzip stream never reaches its end. The
 * install then stops and can fetch the file again instead of carrying on with part of the database.
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

    private int $merged = 0;

    private int $unmatched = 0;

    private bool $readFailed = false;

    public function handle(SaveImportedFoods $save, DumpRow $rows): int
    {
        // The container keeps one command instance, so a second run in the same process starts from zero here.
        $this->imported = $this->skipped = $this->malformed = $this->merged = $this->unmatched = 0;
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
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                $this->error("Could not open {$path}.");

                return self::FAILURE;
            }
            $records = $this->offRows($rows, $handle, str_ends_with($path, '.gz'));
        }

        $batch = [];
        try {
            foreach ($records as $row) {
                $batch[] = $row;
                if (count($batch) >= self::BATCH) {
                    $this->flush($save, $batch);
                    $batch = [];
                }
            }
        } catch (JsonMachineException $e) {
            $this->error("Imported {$this->imported} foods, but {$path} is truncated or corrupt ({$e->getMessage()}). Download it again and re-run the import.");

            return self::FAILURE;
        }
        $this->flush($save, $batch);

        if ($this->readFailed || $this->malformed > 0) {
            $this->error("Imported {$this->imported} foods, but {$path} is truncated or corrupt"
                .($this->malformed > 0 ? " ({$this->malformed} lines are not valid JSON)" : '')
                .'. Download it again and re-run the import.');

            return self::FAILURE;
        }
        if ($this->imported === 0) {
            $this->error("Imported 0 foods, skipped {$this->skipped}. Is this the right file for source {$source->value}?");

            return self::FAILURE;
        }

        $this->info("Imported {$this->imported} foods, skipped {$this->skipped}.");
        // Reported, not failed: these come from the data, so a fresh download would give the same result.
        if ($this->merged > 0 || $this->unmatched > 0) {
            $this->warn("Dropped {$this->unmatched}: rows the database merged into another food, so their servings were not saved (see the log). Merged {$this->merged}: rows that repeated another row's source id.");
        }
        if ($source === FoodSource::OpenFoodFacts) {
            // ODbL needs the credit in the app, and no screen shows it yet (docs/self-hosting.md, "Food data").
            $this->warn('Open Food Facts data is under the ODbL. Show this credit wherever your instance shows food data, until the food detail and Settings → About screens do: '.$source->attribution());
        }

        return self::SUCCESS;
    }

    /** @param  list<Row>  $batch */
    private function flush(SaveImportedFoods $save, array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $result = $save($batch);
        $this->imported += $result['saved'];
        $this->merged += $result['merged'];
        $this->unmatched += $result['unmatched'];
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
    private function offRows(DumpRow $rows, $handle, bool $gzip): Generator
    {
        try {
            foreach ($gzip ? $this->gzipLines($handle) : $this->lines($handle) as $line) {
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
        } finally {
            fclose($handle);
        }
    }

    /**
     * A plain file read to its end. A plain JSONL cut exactly at a line break cannot be told from a whole
     * one; the .gz download can, so docs/self-hosting.md imports that.
     *
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private function lines($handle): Generator
    {
        while (($line = fgets($handle)) !== false) {
            yield $line;
        }
        $this->readFailed = ! feof($handle);
    }

    /**
     * A .gz file inflated here rather than through compress.zlib://, which reports a truncated file as a
     * normal end: the read fails unless the gzip stream reaches its end (its trailer is there).
     *
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private function gzipLines($handle): Generator
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $buffer = '';
        while (! feof($handle)) {
            $chunk = fread($handle, 1 << 20);
            $out = $chunk === false ? false : @inflate_add($inflate, $chunk);
            if ($out === false) {
                $this->readFailed = true;

                return;
            }
            $buffer .= $out;
            $start = 0;
            while (($end = strpos($buffer, "\n", $start)) !== false) {
                yield substr($buffer, $start, $end - $start + 1);
                $start = $end + 1;
            }
            $buffer = substr($buffer, $start);
        }
        if ($buffer !== '') {
            yield $buffer;
        }
        $this->readFailed = inflate_get_status($inflate) !== ZLIB_STREAM_END;
    }
}
