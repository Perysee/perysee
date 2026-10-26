@props(['website'])
<span class="site-monogram" data-site-icon>
    <x-icon name="world" />
    <img src="{{ route('sites.favicon', ['website' => $website, 'v' => substr(hash('sha256', $website->domain), 0, 12)]) }}" alt="" width="20" height="20" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-favicon>
</span>
