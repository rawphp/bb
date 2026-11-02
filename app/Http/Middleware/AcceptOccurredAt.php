<?php

namespace App\Http\Middleware;

use App\Capabilities\Support\IdempotencyKeys;
use App\Support\OccurredAt;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Rawphp\Capabilities\Http\HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the capability routes after auth, and after UseRequestClock, so the window is
 * judged at the walked clock (SET-011). A write that carries X-BB-Occurred-At runs with
 * that instant as {@see OccurredAt::get()}; one outside the window (up to 7 days old, up
 * to 5 minutes ahead) is refused before it runs, in the capability error shape (OFF-007,
 * CAP-012). A write the server already holds a live answer for under its Idempotency-Key
 * passes whatever its age, so a lost answer sent again gets the stored one (CAP-014).
 */
final class AcceptOccurredAt
{
    public function __construct(private readonly IdempotencyKeys $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->header(OccurredAt::HEADER);

        if (! is_string($value)) {
            return $next($request);
        }

        $at = OccurredAt::parse($value);
        $refusal = OccurredAt::refusal($at, CarbonImmutable::now());

        if ($refusal !== null && ! $this->answered($request)) {
            return HttpResponse::failure('validation_failed', $refusal, [
                'violations' => [['field' => 'occurred_at', 'message' => $refusal]],
            ])->toIlluminate();
        }

        $request->attributes->set(OccurredAt::ATTRIBUTE, $at);

        return $next($request);
    }

    /** Whether the store holds an unexpired answer for this user's write under its key, as the pipeline looks it up. */
    private function answered(Request $request): bool
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();
        $name = $request->route('name');

        if (! is_string($key) || $key === '' || ! is_object($user) || ! is_string($name)) {
            return false;
        }

        return $this->keys->answered($user, $name, $key);
    }
}
