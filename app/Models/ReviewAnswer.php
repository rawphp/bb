<?php

namespace App\Models;

use App\Domain\WeekBuilder\CalorieMove;
use App\Models\Concerns\BelongsToUser;
use App\Support\Review\ReviewAnswers;
use Database\Factories\ReviewAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The weekly review's answers for one reviewed week (REV-004, REV-031), one row per user per week. review_answer
 * stores them, review_show shows them and review_complete applies them.
 *
 * @property list<array{from_index: int, to_index: int, kcal: int}> $moves
 */
#[Fillable(['week_start', 'option', 'own_kcal', 'deload_too', 'update_step_plan', 'moves', 'refusal'])]
class ReviewAnswer extends Model
{
    /** @use HasFactory<ReviewAnswerFactory> */
    use BelongsToUser, HasFactory, HasUuids;

    /** The user's stored answers for the week starting `$weekStart` (Y-m-d); the unanswered defaults when there are none. */
    public static function answersFor(User $user, string $weekStart): ReviewAnswers
    {
        return self::forWeek($user, $weekStart)?->answers() ?? ReviewAnswers::defaults();
    }

    public static function forWeek(User $user, string $weekStart, bool $lock = false): ?self
    {
        return self::query()->where('user_id', $user->id)->whereDate('week_start', $weekStart)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * REV-010: Choose my own answered with no amount yet, a draft. Every write that applies the answers refuses it, so
     * the suggested amount review_show fills in is never applied in the user's place.
     */
    public function needsAmount(): bool
    {
        return $this->option === 'choose_own' && $this->own_kcal === null;
    }

    /** The row as the review's rules read it. */
    public function answers(): ReviewAnswers
    {
        return new ReviewAnswers(
            option: $this->option,
            ownKcal: $this->own_kcal,
            deloadToo: $this->deload_too,
            updateStepPlan: $this->update_step_plan,
            moves: self::calorieMoves($this->moves),
            refusal: $this->refusal,
        );
    }

    /**
     * @param  list<array{from_index: int, to_index: int, kcal: int}>  $moves
     * @return list<CalorieMove>
     */
    public static function calorieMoves(array $moves): array
    {
        return array_map(fn (array $move) => new CalorieMove((int) $move['from_index'], (int) $move['to_index'], (float) $move['kcal']), $moves);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'week_start' => 'date:Y-m-d',
            'own_kcal' => 'integer',
            'deload_too' => 'boolean',
            'update_step_plan' => 'boolean',
            'moves' => 'array',
        ];
    }
}
