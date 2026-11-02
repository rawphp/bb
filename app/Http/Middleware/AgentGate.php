<?php

namespace App\Http\Middleware;

use App\Agent\AgentStatus;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Rawphp\Capabilities\Support\CapabilityResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of the package's chat routes: a new message, which starts a turn, is
 * refused while the agent is off (AGT-002), the user is at a cap (AGT-005), or,
 * under a token cap, one of the user's turns is still queued or running.
 * Under a cap, the checks and the store run under one lock per user. Nothing is stored or queued then. Every other chat route and every screen keep working.
 *
 * On every chat route, a personal access token limited to some capabilities is
 * refused (CAP-023): the Coach's tool calls run as the user with no token, so
 * through it a Health bridge token would reach every write. A token that may
 * call every capability (`*`) is let through.
 */
final class AgentGate
{
    /** Cache lock name prefix, per user. */
    public const LOCK = 'agent-gate:';

    /** Longest a crashed request can hold the lock. Storing a message takes milliseconds. */
    private const LOCK_SECONDS = 30;

    /** Longest an overlapping post waits for the one before it. */
    public const WAIT_SECONDS = 3;

    public function __construct(private readonly AgentStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! $token->can('*')) {
            return self::refuse(CapabilityResult::failure('forbidden', 'This token can\'t use the Coach.'));
        }

        if (! $request->routeIs('capabilities-ai.messages.store') || $request->user() === null) {
            return $next($request);
        }

        if (! $this->status->enabled()) {
            return self::refuse(CapabilityResult::failure('forbidden', 'The Coach is off on this instance.'));
        }

        if (! $this->status->capped()) {
            return $next($request);
        }

        $userId = $request->user()->getAuthIdentifier();

        // Under a cap, checked and stored under one lock per user, so parallel posts
        // cannot all pass the checks before any of them has stored its turn. A post
        // that overlaps another waits for it, then is checked against what it stored.
        try {
            return Cache::lock(self::LOCK.$userId, self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($request, $next, $userId): Response {
                $allowance = $this->status->allowance($userId);
                if ($allowance->atCap) {
                    return self::refuse(CapabilityResult::failure('rate_limited', (string) $allowance->notice, [
                        'resets_at' => $allowance->resetsAt?->toIso8601String(),
                    ]));
                }

                if ($this->status->busy($userId)) {
                    return self::refuse(CapabilityResult::failure('rate_limited', 'The Coach is still answering your last message.'));
                }

                return $next($request);
            });
        } catch (LockTimeoutException) {
            return self::refuse(CapabilityResult::failure('rate_limited', 'Your last Coach message is still being sent. Try again in a moment.'));
        }
    }

    private static function refuse(CapabilityResult $result): JsonResponse
    {
        return new JsonResponse($result->toArray(), (int) $result->error['http_status']);
    }
}
