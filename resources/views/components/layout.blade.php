@props(['title' => 'Perysee', 'website' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Perysee</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <script src="{{ asset('color-mode.js') }}"></script>
    @vite(['resources/css/legacy.css'])
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <header><a href="{{ route('sites.index') }}"><x-brand /></a></header>
    <main id="main">{{ $slot }}</main>
    <footer>Local Lueur test page · Your data. Your infrastructure.</footer>
</body>
</html>
