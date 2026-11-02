<?php

namespace App\Http\Middleware;

use App\Support\OccurredAt;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Rawphp\Capabilities\Http\HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the api group after UseRequestClock, so the window is judged at the walked
 * clock (SET-011). A write that carries X-BB-Occurred-At runs with that instant as
 * {@see OccurredAt::get()}; one outside the window (up to 7 days old, up to 5 minutes
 * ahead) is refused before it runs, in the capability error shape (OFF-007, CAP-012).
 */
final class AcceptOccurredAt
{
    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->header(OccurredAt::HEADER);

        if (! is_string($value)) {
            return $next($request);
        }

        $at = OccurredAt::parse($value);
        $refusal = OccurredAt::refusal($at, CarbonImmutable::now());

        if ($refusal !== null) {
            return HttpResponse::failure('validation_failed', $refusal, [
                'violations' => [['field' => 'occurred_at', 'message' => $refusal]],
            ])->toIlluminate();
        }

        $request->attributes->set(OccurredAt::ATTRIBUTE, $at);

        return $next($request);
    }
}
