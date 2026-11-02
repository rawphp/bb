<?php

namespace App\Models;

use App\Domain\Shopping\DateRange;
use App\Domain\Shopping\TickedRange;
use App\Models\Concerns\BelongsToUser;
use App\Support\Mesocycles\TrainSnapshot;
use Carbon\CarbonImmutable;
use Database\Factories\ShoppingTicksFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The shopping lines a user ticked off in one date range (SHOP-005): what rp-clone kept on the device.
 * One row per user and range.
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

    /**
     * The user's ticked ranges, first day first.
     *
     * @return list<TickedRange>
     */
    public static function of(User $user): array
    {
        return self::query()->where('user_id', $user->id)->orderBy('starts_on')->orderBy('ends_on')->get()
            ->map(fn (self $row) => $row->ticked())
            ->values()
            ->all();
    }

    public function ticked(): TickedRange
    {
        return new TickedRange(new DateRange(TrainSnapshot::day($this->starts_on), TrainSnapshot::day($this->ends_on)), $this->keys);
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
