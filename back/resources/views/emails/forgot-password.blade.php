Hola {{ $firstName }},

Recibimos una solicitud para recuperar la contraseña de tu cuenta en {{ config('app.name') }}.

Ingresá el siguiente código para continuar:

{{ $code }}

Este código expira en {{ $expirationMinutes }} minutos.

Si no solicitaste este cambio, podés ignorar este mensaje. Tu contraseña no será modificada.

Saludos,
El equipo de {{ config('app.name') }}
