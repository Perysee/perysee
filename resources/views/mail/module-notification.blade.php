{!! $notification->body !!}
@if ($notification->url !== null)

{!! rtrim((string) config('app.url'), '/').$notification->url !!}
@endif

-- {!! $appName !!}
