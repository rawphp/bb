<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Push\UnsubscribePush;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class SignOutController
{
    public function __invoke(Request $request, UnsubscribePush $unsubscribe): Response
    {
        // This browser stops getting the account's notifications (NOTIF-010).
        $endpoint = PushSubscription::browserEndpoint($request);
        $user = $request->user();
        if ($endpoint !== null && $user instanceof User) {
            $unsubscribe($user, $endpoint);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent()->withoutCookie(PushSubscription::BROWSER_COOKIE);
    }
}
