<?php

namespace App\Agent;

use App\Models\ClearedCoachTurn;
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

    public function allowance(int|string $userId): Allowance
    {
        $now = CarbonImmutable::now();
        $turnsPerDay = $this->config->get('agent.turns_per_day');
        $tokensPerMonth = $this->config->get('agent.tokens_per_month');

        return Allowance::check(
            $turnsPerDay,
            $tokensPerMonth,
            turnsToday: $turnsPerDay === null ? 0 : $this->turnsSince($userId, $now->startOfDay()),
            tokensThisMonth: $tokensPerMonth === null ? 0 : $this->tokensSince($userId, $now->startOfMonth()),
            now: $now,
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
