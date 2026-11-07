{!! $inviter ?? 'An owner' !!} invited you to join "{!! $siteName !!}" on {!! $appName !!} as {{ $role === 'owner' ? 'an owner' : 'a viewer' }}.

Open this link to accept the invitation:
{!! $url !!}

The link works once and expires on {{ $expiresOn }}. If you were not expecting this message, ignore it: nothing happens until the link is used.
