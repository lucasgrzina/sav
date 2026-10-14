{!! $body !!}
@if (!empty($actionUrl))

{!! $actionLabel ?: 'Abrir enlace' !!}: {!! $actionUrl !!}
@endif

Saludos,
El equipo de {{ config('app.name') }}
