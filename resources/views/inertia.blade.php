<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <script src="{{ asset('color-mode.js') }}"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=5">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
    <noscript><p>Perysee's interface requires JavaScript. Your tracking and scheduled consolidation remain independent of this interface.</p></noscript>
</body>
</html>
