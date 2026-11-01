<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

// The Vue app shell answers every GET the client router owns (CAP-004).
// The dev screens and gallery under /dev exist in local and testing only.
final class AppShellController
{
    public function __invoke(?string $path = null): View
    {
        $devRoutes = app()->environment('local', 'testing');

        abort_if(! $devRoutes && preg_match('~^dev(/|$)~', (string) $path) === 1, 404);

        return view('app', ['devRoutes' => $devRoutes]);
    }
}
