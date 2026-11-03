<?php

// The php -S router of scripts/e2e-server.sh: Laravel's own (server.php) serves public/ and boots public/index.php,
// and this does the same with the scripted Coach registered after the app's providers (tests/Support/ScriptedCoach.php).
// Only the e2e server runs it; the app's code is untouched.

use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Tests\Support\ScriptedCoachProvider;

$root = dirname(__DIR__, 2);
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($uri !== '/' && file_exists($root.'/public'.$uri)) {
    return false;
}

define('LARAVEL_START', microtime(true));
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->afterBootstrapping(RegisterProviders::class, fn ($app) => $app->register(ScriptedCoachProvider::class));
$app->handleRequest(Request::capture());
