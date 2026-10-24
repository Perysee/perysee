<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Perysee — See what matters.</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('logo.svg') }}">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    <style>
        :root{font-family:Arial,sans-serif;color:#1d1d1d;background:#fafaf9;color-scheme:light}*{box-sizing:border-box}body{margin:0}main{max-width:960px;margin:0 auto;padding:48px 24px}header{display:flex;align-items:center;gap:12px;font-size:23px;font-weight:600}header img{width:32px;height:32px}.eyebrow{margin-top:100px;color:#E20E6A;font-size:14px;font-weight:500}h1{font-size:clamp(40px,7vw,72px);line-height:1.05;letter-spacing:-.05em;font-weight:500;margin:24px 0}p{color:#686363;line-height:1.7;max-width:560px}.grid{display:grid;grid-template-columns:repeat(3,1fr);margin:48px 0;border-top:1px solid #e7e5e4;border-bottom:1px solid #e7e5e4}.grid div{padding:24px 16px 24px 0}.grid strong{display:block;font-size:14px}.grid span{display:block;color:#78716c;font-size:12px;margin-top:8px}.links{display:flex;flex-wrap:wrap;gap:12px}a{color:inherit;text-decoration:none;border:1px solid #d6d3d1;padding:12px 18px;font-size:13px}a:first-child{color:white;background:#E20E6A;border-color:#E20E6A}a:focus-visible{outline:3px solid #E20E6A;outline-offset:4px}footer{margin-top:80px;color:#78716c;font-size:11px}@media(max-width:550px){.grid{grid-template-columns:1fr}.grid div+div{border-top:1px solid #e7e5e4}.eyebrow{margin-top:64px}}
    </style>
</head>
<body>
    <main>
        <header><img src="{{ asset('logo.svg') }}" alt="">Perysee<span style="color:#E20E6A">.</span></header>
        <div class="eyebrow">Self-hosted web analytics</div>
        <h1>See what matters.<br><span style="color:#E20E6A">Your data. Your infrastructure.</span></h1>
        <p>Understand your audience with focused web analytics, hosted on your own infrastructure.</p>
        <div class="grid">
            <div><strong>PHP 8.3+ · Laravel 13</strong><span>Built for your own hosting</span></div>
            <div><strong>MariaDB 11.4.8</strong><span>Your own analytics database</span></div>
            <div><strong>A focused architecture</strong><span>No Redis or permanent workers required</span></div>
        </div>
        <div class="links">
            <a href="{{ config('perysee.docs_url') }}">Read the documentation ↗</a>
            <a href="{{ config('perysee.landing_url') }}">Explore Perysee ↗</a>
        </div>
        <footer>Perysee · See what matters.</footer>
    </main>
</body>
</html>
