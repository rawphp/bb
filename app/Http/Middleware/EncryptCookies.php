<?php

namespace App\Http\Middleware;

use App\Models\PushSubscription;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;

/**
 * The web group's cookie encryption, minus the cookie the app writes itself to
 * name this browser's push endpoint (NOTIF-010). A subclass rather than
 * `encryptCookies(except:)`, which sets a static that tests flush between cases.
 */
final class EncryptCookies extends BaseEncryptCookies
{
    /** @var array<int, string> */
    protected $except = [PushSubscription::BROWSER_COOKIE];
}
