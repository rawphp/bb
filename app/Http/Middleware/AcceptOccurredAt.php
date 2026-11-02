<?php

namespace App\Http\Middleware;

use App\Support\OccurredAt;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Rawphp\Capabilities\Contracts\IdempotencyStore;
use Rawphp\Capabilities\Http\HttpResponse;
use Rawphp\Capabilities\Pipeline\ResolveActor;
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
    /**
     * The tenant the package's DefaultScopeResolver gives every invoke in this
     * single-tenant app, so the lookup finds what the pipeline stored. The feature
     * test that replays a lost write after its window fails if this drifts.
     */
    private const TENANT = 'default-tenant';

    public function __construct(private readonly IdempotencyStore $answers) {}

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

        return $this->answers->find(self::TENANT, ResolveActor::actorType($user), ResolveActor::actorId($user), $name, $key) !== null;
    }
}
