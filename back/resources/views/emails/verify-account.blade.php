Hola {{ $firstName }},

Gracias por registrarte en {{ config('app.name') }}.

Para verificar tu cuenta, ingresá el siguiente código:

{{ $code }}

Este código expira en {{ $expirationMinutes }} minutos.

Si no creaste esta cuenta, podés ignorar este mensaje.

Saludos,
El equipo de {{ config('app.name') }}
