<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ config('app.name') }}</title>
@if ($devRoutes)
    <meta name="bb-dev-routes" content="1">
@endif
    @vite('resources/js/app/main.ts')
</head>
<body>
    <div id="app"></div>
</body>
</html>
