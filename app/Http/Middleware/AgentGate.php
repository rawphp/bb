<?php

namespace App\Http\Middleware;

use App\Agent\AgentStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Rawphp\Capabilities\Support\CapabilityResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of the package's chat routes: a new message, which starts a turn, is
 * refused while the agent is off (AGT-002), the user is at a cap (AGT-005), or,
 * under a token cap, one of the user's turns is still queued or running.
 * The checks and the store run under one lock per user. Nothing is stored or queued then. Every other chat route and every screen keep working.
 */
final class AgentGate
{
    /** Cache lock name prefix, per user. */
    public const LOCK = 'agent-gate:';

    /** Longest a crashed request can hold the lock. Storing a message takes milliseconds. */
    private const LOCK_SECONDS = 30;

    private const BUSY = 'The Coach is still answering your last message.';

    public function __construct(private readonly AgentStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('capabilities-ai.messages.store') || $request->user() === null) {
            return $next($request);
        }

        if (! $this->status->enabled()) {
            return self::refuse(CapabilityResult::failure('forbidden', 'The Coach is off on this instance.'));
        }

        $userId = $request->user()->getAuthIdentifier();

        // Checked and stored under one lock per user, so parallel posts cannot all
        // pass the checks before any of them has stored its turn.
        $lock = Cache::lock(self::LOCK.$userId, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return self::refuse(CapabilityResult::failure('rate_limited', self::BUSY));
        }

        try {
            $allowance = $this->status->allowance($userId);
            if ($allowance->atCap) {
                return self::refuse(CapabilityResult::failure('rate_limited', (string) $allowance->notice, [
                    'resets_at' => $allowance->resetsAt?->toIso8601String(),
                ]));
            }

            if ($this->status->busy($userId)) {
                return self::refuse(CapabilityResult::failure('rate_limited', self::BUSY));
            }

            return $next($request);
        } finally {
            $lock->release();
        }
    }

    private static function refuse(CapabilityResult $result): JsonResponse
    {
        return new JsonResponse($result->toArray(), (int) $result->error['http_status']);
    }
}
