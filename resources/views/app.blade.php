<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ config('app.name') }}</title>
@if ($devRoutes)
    <meta name="bb-dev-routes" content="1">
@endif
    {{-- The stored appearance before the first paint (PLT-005); resources/js/design/appearance.ts owns the rule. --}}
    <script>try { var a = localStorage.getItem('appearance'); if (a === 'light' || a === 'dark') document.documentElement.dataset.appearance = a; } catch (e) {}</script>
    @vite('resources/js/app/main.ts')
</head>
<body>
    <div id="app"></div>
</body>
</html>
