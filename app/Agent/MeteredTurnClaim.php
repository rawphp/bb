<?php

namespace App\Agent;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Rawphp\CapabilitiesAi\Contracts\TurnClaim;
use Rawphp\CapabilitiesAi\Models\TableNames;

/**
 * The package's turn claim, plus each turn's LLM token total in `turns.tokens`
 * wherever the package records the rounds' usage. The AGT-005 monthly cap then
 * sums one column in the database instead of decoding every turn's usage.
 */
final class MeteredTurnClaim implements TurnClaim
{
    public function __construct(private readonly TurnClaim $claim) {}

    public function claim(string $turnUlid, string $owner): bool
    {
        return $this->claim->claim($turnUlid, $owner);
    }

    public function isRunning(string $turnUlid): bool
    {
        return $this->claim->isRunning($turnUlid);
    }

    public function complete(string $turnUlid, array $usage): bool
    {
        $done = $this->claim->complete($turnUlid, $usage);
        self::meter($turnUlid, $usage);

        return $done;
    }

    public function fail(string $turnUlid, string $error, array $usage): bool
    {
        $done = $this->claim->fail($turnUlid, $error, $usage);
        self::meter($turnUlid, $usage);

        return $done;
    }

    public function recordUsage(string $turnUlid, array $usage): void
    {
        $this->claim->recordUsage($turnUlid, $usage);
        self::meter($turnUlid, $usage);
    }

    public function failUnclaimed(string $turnUlid, string $error): bool
    {
        return $this->claim->failUnclaimed($turnUlid, $error);
    }

    public function cancel(string $turnUlid): bool
    {
        return $this->claim->cancel($turnUlid);
    }

    public function failStaleQueued(DateTimeInterface $createdBefore, string $error, DateTimeInterface $now): array
    {
        return $this->claim->failStaleQueued($createdBefore, $error, $now);
    }

    public function failStaleRunning(DateTimeInterface $claimedBefore, string $error, DateTimeInterface $now): array
    {
        return $this->claim->failStaleRunning($claimedBefore, $error, $now);
    }

    /**
     * The tokens were spent whether or not the status change won its race, so
     * they count either way.
     *
     * @param  list<array<string, int>>  $usage
     */
    private static function meter(string $turnUlid, array $usage): void
    {
        $tokens = 0;
        foreach ($usage as $round) {
            $tokens += (int) ($round['input_tokens'] ?? 0) + (int) ($round['output_tokens'] ?? 0);
        }

        DB::table(TableNames::turns())->where('ulid', $turnUlid)->update(['tokens' => $tokens]);
    }
}
