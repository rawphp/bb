<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// The Vue app shell answers every GET the client router owns (CAP-004).
// The dev screens and gallery under /dev exist in local and testing only.
final class AppShellController
{
    public function __invoke(Request $request, ?string $path = null): View|RedirectResponse
    {
        // ONB-001: a signed-out visit to / opens the intro cards. Once the service worker
        // is installed it answers navigations from its cached shell, so in practice only
        // a device's first visit reaches this, and a signed-in user is never sent there.
        // Only a page load is sent: the worker's own fetch of / is the shell it caches (OFF-002).
        if ($path === null && auth()->guest() && $request->header('Sec-Fetch-Mode') === 'navigate') {
            return redirect('/welcome');
        }

        $devRoutes = app()->environment('local', 'testing');

        abort_if(! $devRoutes && preg_match('~^dev(/|$)~', (string) $path) === 1, 404);

        return view('app', ['devRoutes' => $devRoutes]);
    }
}
