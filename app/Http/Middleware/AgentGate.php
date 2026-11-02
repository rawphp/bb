<?php

namespace App\Http\Middleware;

use App\Agent\AgentStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rawphp\Capabilities\Support\CapabilityResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of the package's chat routes: a new message, which starts a turn, is
 * refused while the agent is off (AGT-002), the user is at a cap (AGT-005), or,
 * under a token cap, one of the user's turns is still queued or running.
 * Nothing is stored or queued then. Every other chat route and every screen keep working.
 */
final class AgentGate
{
    public function __construct(private readonly AgentStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('capabilities-ai.messages.store') || $request->user() === null) {
            return $next($request);
        }

        if (! $this->status->enabled()) {
            return self::refuse(CapabilityResult::failure('forbidden', 'The Coach is off on this instance.'));
        }

        $allowance = $this->status->allowance($request->user()->getAuthIdentifier());
        if ($allowance->atCap) {
            return self::refuse(CapabilityResult::failure('rate_limited', (string) $allowance->notice, [
                'resets_at' => $allowance->resetsAt?->toIso8601String(),
            ]));
        }

        if ($this->status->busy($request->user()->getAuthIdentifier())) {
            return self::refuse(CapabilityResult::failure('rate_limited', 'The Coach is still answering your last message.'));
        }

        return $next($request);
    }

    private static function refuse(CapabilityResult $result): JsonResponse
    {
        return new JsonResponse($result->toArray(), (int) $result->error['http_status']);
    }
}
