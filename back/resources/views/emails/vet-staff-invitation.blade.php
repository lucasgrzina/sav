Hola {{ $firstName }},

Fuiste invitado/a a formar parte del equipo de {{ $vetName }} en {{ config('app.name') }}.

Para activar tu cuenta y establecer tu contraseña, hacé clic en el siguiente enlace:

{{ $invitationUrl }}

Este enlace expira en {{ $expirationHours }} horas.

Si no esperabas esta invitación, podés ignorar este mensaje.

Saludos,
El equipo de {{ config('app.name') }}
