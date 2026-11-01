<?php

namespace App\Agent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Rawphp\CapabilitiesAi\Models\TableNames;

/**
 * Whether the instance runs the agent (AGT-002) and how much of the AGT-005
 * caps a user has used. Every turn counts towards the day; the tokens of every
 * LLM round count towards the month.
 */
final class AgentStatus
{
    public function __construct(private readonly Repository $config) {}

    public function enabled(): bool
    {
        return $this->config->get('capabilities-ai.llm.driver') === 'anthropic'
            && filled($this->config->get('capabilities-ai.llm.anthropic.api_key'));
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

    private function turnsSince(int|string $userId, CarbonImmutable $since): int
    {
        return $this->turns($userId, $since)->count();
    }

    private function tokensSince(int|string $userId, CarbonImmutable $since): int
    {
        $tokens = 0;
        foreach ($this->turns($userId, $since)->whereNotNull('turns.usage')->pluck('turns.usage') as $usage) {
            foreach (json_decode((string) $usage, true) ?: [] as $round) {
                $tokens += (int) ($round['input_tokens'] ?? 0) + (int) ($round['output_tokens'] ?? 0);
            }
        }

        return $tokens;
    }

    private function turns(int|string $userId, CarbonImmutable $since): Builder
    {
        return DB::table(TableNames::turns().' as turns')
            ->join(TableNames::conversations().' as conversations', 'conversations.id', '=', 'turns.conversation_id')
            ->where('conversations.user_id', (string) $userId)
            ->where('turns.created_at', '>=', $since);
    }
}
