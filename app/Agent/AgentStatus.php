<?php

namespace App\Agent;

use App\Models\ClearedCoachTurn;
use App\Models\User;
use App\Support\UserDay;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Rawphp\CapabilitiesAi\Models\TableNames;
use Rawphp\CapabilitiesAi\Models\Turn;

/**
 * Whether the instance runs the agent (AGT-002) and how much of the AGT-005
 * caps a user has used. Every turn counts towards the day; the tokens of every
 * LLM round count towards the month, from `turns.tokens` (MeteredTurnClaim). The turns
 * of a conversation the user cleared still count, from ClearedCoachTurn (AGT-020).
 * The day is the user's local day (SET-012); the month is the app's. A time zone change gives no fresh daily
 * allowance for 24 hours (AGT-005, SET-012): until then the day's turns count from 24 hours before the change.
 */
final class AgentStatus
{
    public function __construct(private readonly Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('capabilities-ai.llm.driver') === 'anthropic'
            && filled($this->config->get('capabilities-ai.llm.anthropic.api_key'));
    }

    /** The ULID of the user's newest conversation, or null when they have none (AGT-024). */
    public function conversationUlid(int|string $userId): ?string
    {
        $ulid = DB::table(TableNames::conversations())->where('user_id', (string) $userId)->orderByDesc('id')->value('ulid');

        return is_string($ulid) ? $ulid : null;
    }

    /** Whether the instance sets either AGT-005 cap. */
    public function capped(): bool
    {
        return $this->config->get('agent.turns_per_day') !== null
            || $this->config->get('agent.tokens_per_month') !== null;
    }

    public function allowance(User $user): Allowance
    {
        $now = CarbonImmutable::now();
        $turnsPerDay = $this->config->get('agent.turns_per_day');
        $tokensPerMonth = $this->config->get('agent.tokens_per_month');
        $appZone = $this->config->get('app.timezone');
        [$dayStarts, $dayEnds] = UserDay::bounds($user, UserDay::today($user));
        $dayEndsAt = CarbonImmutable::instance($dayEnds)->addSecond()->setTimezone(UserDay::zone($user));
        $userId = $user->getAuthIdentifier();
        // created_at is stored in the app timezone, and a bound date is not converted.
        $turnsToday = $turnsPerDay === null ? 0 : $this->turnsSince($userId, CarbonImmutable::instance($dayStarts)->setTimezone($appZone));

        $changedAt = $user->time_zone_changed_at === null ? null : CarbonImmutable::instance($user->time_zone_changed_at);
        if ($turnsPerDay !== null && $changedAt !== null && $changedAt > $now->subDay()) {
            // Within a day of a zone change the day runs from a day before it, which covers the old zone's whole
            // day, and ends a day after it, unless the new local day's own turns hold the cap to its later midnight.
            $windowEndsAt = $changedAt->addDay();
            if (! ($dayEndsAt > $windowEndsAt && $turnsToday >= $turnsPerDay)) {
                $dayEndsAt = $windowEndsAt->setTimezone(UserDay::zone($user));
            }
            $turnsToday = $this->turnsSince($userId, $changedAt->subDay()->setTimezone($appZone));
        }

        return Allowance::check(
            $turnsPerDay,
            $tokensPerMonth,
            turnsToday: $turnsToday,
            tokensThisMonth: $tokensPerMonth === null ? 0 : $this->tokensSince($userId, $now->startOfMonth()),
            now: $now,
            dayEndsAt: $dayEndsAt,
        );
    }

    /**
     * Under a monthly token cap a user has one turn at a time: a turn's tokens
     * are known only once it ends, so this keeps the overshoot to that one turn.
     */
    public function busy(int|string $userId): bool
    {
        return $this->config->get('agent.tokens_per_month') !== null
            && $this->turns($userId, CarbonImmutable::createFromTimestamp(0))
                ->whereIn('turns.status', [Turn::STATUS_QUEUED, Turn::STATUS_RUNNING])
                ->exists();
    }

    private function turnsSince(int|string $userId, CarbonImmutable $since): int
    {
        return $this->turns($userId, $since)->count() + $this->cleared($userId, $since)->count();
    }

    private function tokensSince(int|string $userId, CarbonImmutable $since): int
    {
        return (int) $this->turns($userId, $since)->sum('turns.tokens') + (int) $this->cleared($userId, $since)->sum('tokens');
    }

    /** @return EloquentBuilder<ClearedCoachTurn> */
    private function cleared(int|string $userId, CarbonImmutable $since): EloquentBuilder
    {
        return ClearedCoachTurn::query()->where('user_id', $userId)->where('created_at', '>=', $since);
    }

    private function turns(int|string $userId, CarbonImmutable $since): Builder
    {
        return DB::table(TableNames::turns().' as turns')
            ->join(TableNames::conversations().' as conversations', 'conversations.id', '=', 'turns.conversation_id')
            ->where('conversations.user_id', (string) $userId)
            ->where('turns.created_at', '>=', $since);
    }
}
