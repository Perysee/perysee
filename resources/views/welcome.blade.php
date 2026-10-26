<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Perysee — See what matters.</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/legacy.css'])
    @endif
    <script src="{{ asset('color-mode.js') }}"></script>
    <style>
        :root{font-family:Arial,sans-serif;color:var(--ui-text);background:var(--ui-bg);}*{box-sizing:border-box}body{margin:0}main{max-width:960px;margin:0 auto;padding:48px 24px}header{display:flex;align-items:center;gap:12px;font-size:23px;font-weight:600}header img{width:32px;height:32px}.eyebrow{margin-top:100px;color:var(--ui-text);font-size:14px;font-weight:500}h1{font-size:clamp(40px,7vw,72px);line-height:1.05;letter-spacing:-.05em;font-weight:500;margin:24px 0}p{color:var(--ui-text);line-height:1.7;max-width:560px}.grid{display:grid;grid-template-columns:repeat(3,1fr);margin:48px 0;border-top:1px solid var(--ui-border);border-bottom:1px solid var(--ui-border)}.grid div{padding:24px 16px 24px 0}.grid strong{display:block;font-size:14px}.grid span{display:block;color:var(--ui-text);font-size:12px;margin-top:8px}.links{display:flex;flex-wrap:wrap;gap:12px}a{color:inherit;text-decoration:none;border:1px solid var(--ui-border);padding:12px 18px;font-size:13px}a:first-child{color:#F8F8F8;background:#5516FF;border-color:#5516FF}a:focus-visible{outline:3px solid #5516FF;outline-offset:4px}footer{margin-top:80px;color:var(--ui-text);font-size:11px}@media(max-width:550px){.grid{grid-template-columns:1fr}.grid div+div{border-top:1px solid var(--ui-border)}.eyebrow{margin-top:64px}}
    </style>
</head>
<body>
    <main>
        <header><img class="brand-logo-light" src="{{ asset('logo.svg') }}" alt=""><img class="brand-logo-dark" src="{{ asset('logo-dark.svg') }}" alt="">Perysee<span style="color:var(--ui-text)">.</span></header>
        <div class="eyebrow">Self-hosted web analytics</div>
        <h1>See what matters.<br><span style="color:var(--ui-text)">Your data. Your infrastructure.</span></h1>
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
