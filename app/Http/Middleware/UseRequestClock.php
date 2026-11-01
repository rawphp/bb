<?php

namespace App\Http\Middleware;

use App\Support\RequestClock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * First in the api group, so capability and chat routes run at X-BB-Now (SET-011).
 * Local and testing only; see App\Support\RequestClock. The clock walks forward only:
 * a past instant would date the session and XSRF cookies in the past and sign the user out.
 */
final class UseRequestClock
{
    public function handle(Request $request, Closure $next): Response
    {
        $at = RequestClock::parse($request->header(RequestClock::HEADER));

        if ($at === null || $at->lessThan(Carbon::now())) {
            return $next($request);
        }

        RequestClock::apply($at);

        try {
            return $next($request);
        } finally {
            RequestClock::release();
        }
    }
}
