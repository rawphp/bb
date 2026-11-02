<?php

namespace App\Models;

use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\TickedRange;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\ShoppingTicksFactory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The shopping lines a user ticked off and the range they were ticked in (SHOP-005): what rp-clone kept
 * on the device. One row per user.
 *
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property list<string> $keys
 */
#[Fillable(['user_id', 'starts_on', 'ends_on', 'keys'])]
class ShoppingTicks extends Model
{
    /** @use HasFactory<ShoppingTicksFactory> */
    use BelongsToUser, HasFactory;

    public function ticked(): TickedRange
    {
        $utc = new DateTimeZone('UTC');

        return new TickedRange(
            new DateRange(
                new DateTimeImmutable($this->starts_on->format('Y-m-d'), $utc),
                new DateTimeImmutable($this->ends_on->format('Y-m-d'), $utc),
            ),
            $this->keys,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'keys' => 'array',
        ];
    }
}
