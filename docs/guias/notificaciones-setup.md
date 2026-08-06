# Guía de configuración de Notificaciones (WhatsApp + Email)

Guía operativa para desarrolladores del equipo SAV. Cubre la configuración completa del
subsistema de **alertas salientes**: proveedores de WhatsApp (Twilio / Kapso), el canal de
email de respaldo, la cola, el scheduler y los webhooks de estado de entrega.

> **Alcance.** En el proyecto convive otro subsistema que también se llama "notificaciones":
> el feed in-app de la campanita (`App\Models\Notification`, tabla `notifications`,
> `routes/api/notifications.php`). **Esta guía NO trata ese módulo.** Acá hablamos del
> namespace `App\Notifications\*` y de las tablas `alerts`, `alert_recipients`, `opt_outs` y
> `whatsapp_webhook_events`.

---

## 1. Cómo viaja una alerta (leer antes de configurar nada)

Entender el flujo evita el 90% de los problemas de setup. La cadena es:

```
Alert (status=pending, scheduled_at)
   │
   │  alerts:dispatch-due  ← scheduler, cada minuto
   ▼
DeliverAlertJob (uno por AlertRecipient)        ← COLA
   │
   ├─ MessageBuilderRegistry  → arma el contenido según AlertType
   ├─ DeliveryPipeline        → aplica OptOutPolicy (puede suprimir)
   └─ GatewayRegistry         → elige el gateway según el Channel del recipient
        │
        ├─ Channel::Whatsapp → TwilioWhatsappGateway | KapsoWhatsappGateway | FakeGateway
        │                       (lo define WHATSAPP_PROVIDER)
        └─ Channel::Email    → MailGateway  (fijo, no configurable)
   │
   ▼
DeliveryResult  → actualiza alert_recipients.status / provider_message_id
   │
   ├─ Failed definitivo → ChannelFallbackService → crea un recipient nuevo en el
   │                       canal de fallback (whatsapp → email) y vuelve a encolar
   │
   └─ Sent → queda esperando el webhook del proveedor para pasar a
             delivered / read / failed
                │
                ├─ POST /api/v1/webhooks/kapso    (HMAC-SHA256 sobre el body crudo)
                └─ POST /api/v1/webhooks/twilio   (HMAC-SHA1 sobre la URL completa + params)
                        │  ambos sin auth:sanctum: la firma ES la autenticación
                        ▼
                WhatsappWebhookEvent (persistido, idempotente)
                        │
                        ▼
                ProcessWhatsappWebhookEventJob   ← COLA
                        │
                        ├─ event_type = whatsapp.message.received
                        │     → RecordInboundOptOut   (baja / alta por palabra clave)
                        │
                        └─ el resto (whatsapp.message.* | twilio.message.*)
                              → RecordDeliveryStatus
```

Tres consecuencias prácticas que hay que tener en la cabeza:

1. **Sin worker de cola y sin scheduler, nada se envía.** Las alertas se quedan en
   `pending` para siempre.
2. **El estado final de una entrega no lo decide el envío, lo decide el webhook.** Un
   `sent` no es un `delivered`.
3. **Por el webhook de Kapso no llegan solo estados.** Un `whatsapp.message.received` (un
   mensaje que manda el destinatario) se rama hacia `RecordInboundOptOut` antes de tocar
   `RecordDeliveryStatus`, y puede escribir una baja en `opt_outs` que suprime los envíos
   futuros de WhatsApp por **ambos** proveedores. Para que esos eventos lleguen hay que
   re-registrar la suscripción en Kapso (ver 7.7).

Enums involucrados:

| Enum | Valores |
| --- | --- |
| `Channel` | `whatsapp`, `sms`, `email`, `push` |
| `DeliveryStatus` | `pending`, `sent`, `delivered`, `read`, `failed`, `suppressed` |
| `AlertType` | `program.task_due`, `program.created`, `program.cancelled`, `health_plan.month`, `event.reminder` |

> Solo `whatsapp` y `email` tienen gateway implementado. Solo los tres `program.*` tienen
> message builder registrado. `sms`, `push`, `health_plan.month` y `event.reminder` existen
> en el enum pero **fallan en runtime** si se usan.

---

## 2. Prerrequisitos

- PHP 8.4 (Laragon). `composer.json` declara `^8.2`, pero el entorno local del proyecto
  requiere el binario 8.4 explícito. Si `php -v` devuelve otra versión, invocar el binario
  de Laragon 8.4 directamente.
- Dependencias instaladas: `composer install` en `back/`.
- Base de datos migrada: `php artisan migrate`.
- Para el flujo de Kapso con webhooks reales: un túnel HTTPS público
  (`cloudflared tunnel --url http://localhost:8000` o equivalente). Kapso **no entrega
  webhooks a HTTP**.

---

## 3. Paso 1 — Variables de entorno

Copiar `back/.env.example` a `back/.env` si todavía no existe. La tabla completa de
variables que afectan a este subsistema:

### 3.1 Selección de proveedor

| Variable | Valor en `.env.example` | Valores válidos |
| --- | --- | --- |
| `WHATSAPP_PROVIDER` | `fake` | `twilio` \| `kapso` \| `fake` |

Dos defaults distintos, a propósito:

- **`.env.example` trae `fake`**, para que un clone recién bajado funcione sin credenciales.
- **El default del código, cuando la variable no existe, sigue siendo `twilio`.** Si en
  producción falta la variable, querés que falle ruidosamente, no que mande todas las alertas
  a un gateway falso y las trague en silencio.

Un valor inválido **no revienta la app**: `channels.whatsapp.gateway` queda en `null` y
`GatewayRegistry::for()` tira `NotificationConfigurationException` recién cuando se resuelve
el canal, con el mensaje
`WHATSAPP_PROVIDER inválido: 'xxx'. Disponibles: twilio, kapso, fake`. Como el
`DeliverAlertJob` trata esa excepción como error definitivo, la alerta no reintenta al vacío:
cae al fallback de email y se entrega igual. Degrada un canal, no la aplicación.

### 3.2 Twilio

| Variable | Default | Notas |
| --- | --- | --- |
| `TWILIO_ACCOUNT_SID` | — | Obligatoria si `WHATSAPP_PROVIDER=twilio` |
| `TWILIO_AUTH_TOKEN` | — | Obligatoria si `WHATSAPP_PROVIDER=twilio` |
| `TWILIO_TEMPLATE_MESSAGE_SERVICE` | — | SID del Messaging Service (el `from`) |
| `TWILIO_TEMPLATE_PROGRAM_CREATED` | — | `contentSid` (`HX…`). Sin default a propósito |
| `TWILIO_TEMPLATE_PROGRAM_CANCELLED` | — | `contentSid` |
| `TWILIO_TEMPLATE_PROGRAM_TASK_DUE` | — | `contentSid`. **Pendiente de aprobación comercial** |
| `TWILIO_STATUS_CALLBACK_URL` | vacío | URL a la que Twilio reporta los estados. Vacía ⇒ `route('webhooks.twilio')` |

Los `contentSid` son *account-scoped*: uno creado en una cuenta no existe en otra. Por eso
no tienen default — un valor faltante debe explotar como error de configuración
(`TemplateNotConfiguredException`), no como un 404 disfrazado de fallo de entrega.

`TWILIO_STATUS_CALLBACK_URL` viene **comentada** en `.env.example`. Es opcional: cuando está
vacía, `NotificationServiceProvider` resuelve `route('webhooks.twilio')` (o sea, `APP_URL` +
`/api/v1/webhooks/twilio`) y se lo pasa al gateway, que lo manda como `statusCallback` en cada
envío. Existe para poder apuntar a un túnel local sin tocar `APP_URL`, que afecta al resto de
la aplicación. Ojo: el esquema de esa URL importa para la validación de firma — ver la entrada
"El webhook de Twilio responde 401" en Troubleshooting.

### 3.3 Kapso

| Variable | Default | Notas |
| --- | --- | --- |
| `KAPSO_API_KEY` | — | Obligatoria si `WHATSAPP_PROVIDER=kapso`. Va en el header `X-API-Key` |
| `KAPSO_PHONE_NUMBER_ID` | — | Obligatoria. Parte de la URL de envío |
| `KAPSO_BUSINESS_ACCOUNT_ID` | — | Opcional. Solo para crear templates; si está vacía se descubre |
| `KAPSO_WEBHOOK_SECRET` | — | Obligatoria para recibir webhooks. Secreto HMAC-SHA256 |
| `KAPSO_TEMPLATE_PROGRAM_CREATED` | `sav_program_created` | Nombre del template en Meta |
| `KAPSO_TEMPLATE_PROGRAM_CANCELLED` | `sav_program_cancelled` | |
| `KAPSO_TEMPLATE_PROGRAM_TASK_DUE` | `sav_program_task_due` | |

Estas cuatro vienen **comentadas** en `.env.example`. Descomentarlas solo si hace falta
desviarse del default:

| Variable | Default | Cuándo tocarla |
| --- | --- | --- |
| `KAPSO_BASE_URL` | `https://api.kapso.ai` | Entorno de Kapso alternativo |
| `KAPSO_API_VERSION` | `v24.0` | Cambio de versión de la Graph API de Meta |
| `KAPSO_TIMEOUT` | `10` | Segundos de timeout HTTP del gateway |
| `KAPSO_TEMPLATE_LANGUAGE` | `es` | Templates en otro idioma (aplica a los tres) |

A diferencia de Twilio, Meta identifica un template por **nombre + idioma**, no por un id
opaco. Por eso estos defaults sí son portables entre cuentas.

### 3.4 Email (canal de fallback)

| Variable | Valor en `.env.example` | Notas |
| --- | --- | --- |
| `MAIL_MAILER` | `log` | `log` escribe el mail en `storage/logs/laravel.log` |
| `MAIL_HOST` | `127.0.0.1` | |
| `MAIL_PORT` | `2525` | Forma típica de Mailpit / Mailhog |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | `null` | |
| `MAIL_FROM_ADDRESS` | `noreply@your-domain.com` | |
| `MAIL_FROM_NAME` | `${APP_NAME}` | |

### 3.5 Cola

| Variable | `.env.example` | Notas |
| --- | --- | --- |
| `QUEUE_CONNECTION` | `database` | Requiere `php artisan queue:work` corriendo |

Los dos jobs (`DeliverAlertJob`, `ProcessWhatsappWebhookEventJob`) usan la **conexión y la cola
por defecto**. No declaran `$queue` ni `$connection`, así que un `queue:work` común los toma.

### 3.6 Después de CUALQUIER cambio en `.env`

```bash
php artisan config:clear
```

Si además se cacheó la configuración en algún momento (`config:cache`), el `.env` deja de
leerse por completo hasta que se limpie. Este es el error de setup más frecuente.

---

## 4. Paso 2 — Elegir el modo de trabajo

Antes de pedir credenciales a nadie, definí en qué modo vas a trabajar.

### Modo A — `fake` (recomendado para desarrollo de features que no son del canal)

```env
WHATSAPP_PROVIDER=fake
```

`FakeGateway` devuelve `DeliveryResult::sent('fake-N')` sin salir a la red. Sirve para
desarrollar y testear todo el pipeline (builders, políticas, fallback, estados) sin tocar
un proveedor real. **Es el valor que trae `.env.example`**: si tu ticket no es del canal de
WhatsApp, no toques nada.

### Modo B — `kapso` (el proveedor con el flujo más completo)

Es el que tiene la mayor superficie implementada: webhooks de estado de entrega, **opt-out
entrante** (el único proveedor por el que llegan mensajes del destinatario) y comandos para
provisionar templates, enviar un WhatsApp real y simular webhooks. Si trabajás sobre entrega,
estados, bajas o fallback, usá este.

### Modo C — `twilio` (default histórico, funcionalmente más acotado)

Envía y **sí recibe estados de entrega por webhook** (ver 8.3 y 8.4): los recipients avanzan a
`delivered`/`read`/`failed`. Lo que sigue faltando es un comando de envío real de prueba propio
de Twilio y la aprobación comercial de `program.task_due` (ver 8.5). Por Twilio tampoco llegan
mensajes entrantes, así que las bajas solo se pueden originar por Kapso — pero una baja
registrada ahí suprime igual los envíos por Twilio, porque `opt_outs` se indexa por
`(phone, channel)` y el canal es `whatsapp` para los dos proveedores.

> **Sobre las credenciales de Twilio.** Si elegís `twilio` con credenciales vacías, el
> service provider valida antes de construir el cliente y tira
> `NotificationConfigurationException` con `Faltan TWILIO_ACCOUNT_SID y/o TWILIO_AUTH_TOKEN.`
> o `Falta TWILIO_TEMPLATE_MESSAGE_SERVICE.` Es un error de configuración definitivo: no
> reintenta y cae al fallback de email. Antes esto explotaba con la excepción cruda del SDK de
> Twilio, que la cola no reconocía como definitiva y reintentaba cinco veces al vacío.

---

## 5. Paso 3 — Cola y scheduler (obligatorio en todos los modos)

Sin estos dos procesos, ninguna alerta sale. Abrir dos terminales y dejarlas corriendo:

```bash
# Terminal 1 — worker de cola
php artisan queue:work

# Terminal 2 — scheduler (ejecuta alerts:dispatch-due cada minuto)
php artisan schedule:work
```

El scheduler está declarado en `back/routes/console.php`:

```php
Schedule::command('alerts:dispatch-due')->everyMinute();
```

`alerts:dispatch-due` busca `Alert` con `status = 'pending'` y `scheduled_at <= now()`,
despacha un `DeliverAlertJob` por cada recipient en `pending`, y marca la alerta como
`dispatched`. Se puede correr a mano para no esperar el minuto:

```bash
php artisan alerts:dispatch-due
```

### Atajo local: `QUEUE_CONNECTION=sync`

```env
QUEUE_CONNECTION=sync
```

Con `sync` los jobs corren inline y no necesitás `queue:work`. Es cómodo, pero **oculta
problemas reales**: no valida el presupuesto de 10 segundos del webhook de Kapso, no ejercita
los reintentos con backoff, y el webhook procesa dentro del propio request HTTP. Usalo para
iterar rápido, no para validar el comportamiento de entrega.

Política de reintentos de los jobs (hardcodeada, no configurable por env):

| Job | `$tries` | `$backoff` (segundos) |
| --- | --- | --- |
| `DeliverAlertJob` | 5 | 60, 300, 900, 1800 |
| `ProcessWhatsappWebhookEventJob` | 3 | 10, 60 |

Los errores de configuración (`NotificationConfigurationException`) y de contacto faltante
(`RecipientContactNotFoundException`) se tratan como **definitivos**: no reintentan, van
directo a `Failed` y disparan el fallback. Reintentar con backoff nunca arregla un template
sin configurar.

---

## 6. Paso 4 — Configurar el canal de email (fallback)

`config('notifications.fallback')` es:

```php
'fallback' => [
    'whatsapp' => ['email'],
],
```

Es decir: **todo WhatsApp que falle definitivamente cae a email.** Si el email no está
configurado, un fallo de WhatsApp se convierte en dos fallos.

Opción más simple para desarrollo:

```env
MAIL_MAILER=log
```

El mail queda escrito en `storage/logs/laravel.log`. No hay nada que instalar.

Opción con inbox visual (Mailpit en el puerto de `.env.example`):

```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
```

> **Trampa.** `MAIL_MAILER=smtp` sin nada escuchando en el 2525 hace que `MailGateway`
> propague la excepción (deliberadamente: `Mail::send()` no distingue fallos definitivos de
> transitorios), y el `DeliverAlertJob` quema sus 5 reintentos con backoff antes de rendirse.
> Si no tenés Mailpit levantado, usá `log`.

Detalles de la implementación de email que conviene conocer:

- El mailable es `App\Mail\AlertMail`, vista `resources/views/emails/alert.blade.php`.
- **No implementa `ShouldQueue`**: se envía sincrónicamente dentro del `DeliverAlertJob`.
- `MailGateway` devuelve un UUID sintético como `provider_message_id`. **No es un id real de
  proveedor**, así que una entrega por email nunca se puede correlacionar con un webhook.
- `Channel::Email` siempre usa `MailGateway`. No es intercambiable por configuración.

---

## 7. Paso 5A — Setup completo de Kapso

Seguir los pasos en orden. Cada uno tiene una verificación.

### 7.1 Credenciales

Pedir al responsable del proyecto Kapso: `KAPSO_API_KEY` y `KAPSO_PHONE_NUMBER_ID`.

```env
WHATSAPP_PROVIDER=kapso
KAPSO_API_KEY=<tu-api-key>
KAPSO_PHONE_NUMBER_ID=<tu-phone-number-id>
```

```bash
php artisan config:clear
```

### 7.2 Generar el secreto del webhook

A diferencia de los webhooks de proyecto (donde el dashboard genera el secreto), en un
webhook de WhatsApp registrado por API **el secreto lo elige quien llama**. Generalo:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Copiar la salida a `.env`:

```env
KAPSO_WEBHOOK_SECRET=<los-64-hex-que-salieron>
```

```bash
php artisan config:clear
```

> El middleware **falla cerrado**: si `KAPSO_WEBHOOK_SECRET` está vacía, el endpoint
> responde `500 KAPSO_WEBHOOK_SECRET no configurado.` No hay modo "sin firma".

### 7.3 Provisionar los templates en Meta

Primero un ensayo sin tocar nada:

```bash
php artisan kapso:create-templates --dry-run
```

Imprime, por cada `AlertType`, el payload JSON completo que se enviaría. No hace ninguna
llamada de red. Revisar que los nombres y los textos sean los esperados.

Cuando esté bien:

```bash
php artisan kapso:create-templates
```

| Opción | Default | Qué hace |
| --- | --- | --- |
| `--business-account-id=` | — | WABA id. Vacío ⇒ se descubre desde `KAPSO_PHONE_NUMBER_ID` |
| `--category=` | `UTILITY` | `AUTHENTICATION` \| `MARKETING` \| `UTILITY` |
| `--dry-run` | — | Muestra los payloads sin crear nada |

Qué hace, paso a paso:

1. Valida la categoría contra `['AUTHENTICATION', 'MARKETING', 'UTILITY']`.
2. Exige `KAPSO_API_KEY` (salvo con `--dry-run`).
3. Arma un payload por cada definición de `WhatsappTemplateCatalog`.
4. Resuelve el WABA id: `--business-account-id` → `KAPSO_BUSINESS_ACCOUNT_ID` → descubrimiento
   vía `GET /platform/v1/whatsapp/phone_numbers`. Si lo descubre, imprime
   `WABA descubierto para <phone>: <waba-id>`.
5. Hace un `POST` por template a
   `{base_url}/meta/whatsapp/{api_version}/{waba}/message_templates`.
6. Imprime `program.created → sav_program_created (id 123, PENDING)` por cada uno.

**Los templates quedan en `PENDING` hasta que Meta los apruebe.** No se puede enviar un
template no aprobado. Esto es una espera externa, no un problema de configuración.

Efectos: solo escribe en la API externa. No toca la base de datos.

### 7.4 Enviar un WhatsApp de prueba

```bash
php artisan kapso:send-test 5491134290838
```

| Argumento / Opción | Default | Qué hace |
| --- | --- | --- |
| `phone` | requerido | Destino. Se normaliza a solo dígitos |
| `--type=` | `program.created` | `AlertType` cuyo template se envía |
| `--text=` | — | Envía texto libre en lugar del template |
| `--name=` | `Lucas` | Valor del placeholder `{{1}}` |

Ejemplos:

```bash
# Template de tarea vencida, con nombre propio
php artisan kapso:send-test 5491134290838 --type=program.task_due --name=Ana

# Texto libre (solo funciona dentro de la ventana de 24h abierta por el destinatario)
php artisan kapso:send-test 5491134290838 --text="Prueba manual"
```

Dos detalles importantes:

- El comando resuelve `KapsoWhatsappGateway` **directamente del contenedor**, salteando el
  `GatewayRegistry`. Funciona incluso con `WHATSAPP_PROVIDER=twilio`.
- **No crea `Alert` ni `AlertRecipient`.** Es un envío puro. No hay nada que mirar en la base
  después de correrlo, salvo el `wamid` que imprime.

Salida esperada:

```
Aceptado por Kapso. wamid: wamid.HBgLNTQ5MTEzNDI5MDgzOBUCABEYEjc...
```

**Guardá ese `wamid`**: es la clave para correlacionar los webhooks.

Errores y qué significan:

| Mensaje | Naturaleza |
| --- | --- |
| `Error de configuración (definitivo, no se reintenta): …` | Falta una env var o el template no está configurado |
| `Fallo transitorio (en producción la cola reintentaría): …` | Red, 5xx, 429 |
| `Rechazo definitivo de Kapso/Meta: …` | 4xx con motivo del proveedor |

### 7.5 Registrar el webhook

Necesitás una URL HTTPS pública apuntando a tu app local. Con cloudflared:

```bash
cloudflared tunnel --url http://localhost:8000
```

Ensayo primero:

```bash
php artisan kapso:register-webhook https://xxxx.trycloudflare.com/api/v1/webhooks/kapso --dry-run
```

El `--dry-run` imprime método, endpoint y payload, con el `secret_key` reemplazado por el
literal `<KAPSO_WEBHOOK_SECRET>`. No hace red.

Luego, de verdad:

```bash
php artisan kapso:register-webhook https://xxxx.trycloudflare.com/api/v1/webhooks/kapso
```

| Argumento / Opción | Qué hace |
| --- | --- |
| `url` | URL pública **HTTPS** del endpoint. Debe terminar en `/api/v1/webhooks/kapso` |
| `--update` | Re-apunta el webhook existente de este número en lugar de crear uno nuevo |
| `--id=` | UUID del webhook a actualizar, cuando hay más de uno para el mismo número |
| `--dry-run` | Muestra el payload sin tocar nada |

Qué hace:

1. Exige `KAPSO_API_KEY`, `KAPSO_PHONE_NUMBER_ID` y `KAPSO_WEBHOOK_SECRET`.
2. Valida que la URL arranque con `https://` — `Kapso no entrega webhooks a HTTP`.
3. Se suscribe exactamente a estos **cinco** eventos:
   `whatsapp.message.received`, `whatsapp.message.sent`, `whatsapp.message.delivered`,
   `whatsapp.message.read`, `whatsapp.message.failed`.
4. `POST /platform/v1/whatsapp/webhooks` (crear) o `PATCH …/webhooks/{id}` (con `--update`).
5. Imprime `id`, `url`, `payload_version` y `events`.

> **`whatsapp.message.received` es nuevo.** Antes la suscripción eran solo los cuatro eventos
> de estado, porque los mensajes entrantes estaban fuera de alcance. Ya no: alimentan el
> opt-out entrante (ver 7.7). Si el webhook de tu entorno se registró antes de este cambio,
> **está suscripto a cuatro eventos y no va a recibir ningún mensaje entrante** hasta que
> corras `kapso:register-webhook <url> --update`.

Cada vez que el túnel cambie de URL (cloudflared da una nueva en cada arranque), re-apuntá el
webhook en lugar de crear otro:

```bash
php artisan kapso:register-webhook https://NUEVA.trycloudflare.com/api/v1/webhooks/kapso --update
```

Si hay más de un webhook para el mismo número, el comando lista los candidatos y pide
`--id=<uuid>`.

> Si el comando avisa
> `El secret que devolvió Kapso NO coincide con KAPSO_WEBHOOK_SECRET: la firma va a fallar con 401.`,
> no lo ignores. Significa exactamente eso: los webhooks van a rebotar con 401.

### 7.6 Verificar el circuito de estados sin esperar a Meta

Este es el comando que más vas a usar. Simula un webhook firmado contra tu propio endpoint:

```bash
php artisan kapso:simulate-webhook wamid.HBgLNTQ5... --event=delivered
```

| Argumento / Opción | Default | Qué hace |
| --- | --- | --- |
| `wamid` | requerido | El message id que devolvió `kapso:send-test` |
| `--event=` | `delivered` | `sent` \| `delivered` \| `read` \| `failed` \| `received` |
| `--code=` | `131047` | Código de error de Meta, solo con `--event=failed` |
| `--title=` | `Re-engagement message` | Detalle del error, solo con `--event=failed` |
| `--from=` | — | Teléfono remitente, solo con `--event=received` |
| `--text=` | `BAJA` | Cuerpo del mensaje entrante, solo con `--event=received` |
| `--idempotency-key=` | — | Repetir la misma clave para probar la deduplicación |
| `--url=` | ruta local `webhooks.kapso` | Endpoint destino |

El `--event=` se traduce a `whatsapp.message.<event>`, así que `--event=received` simula un
mensaje entrante y no un estado de entrega. Ese caso se documenta aparte en 7.7.

Firma la petición con `hash_hmac('sha256', $body, KAPSO_WEBHOOK_SECRET)` y manda los headers
`X-Webhook-Signature`, `X-Webhook-Event`, `X-Idempotency-Key`, `X-Webhook-Payload-Version: v2`.

Después del POST, el comando **lee la base y te reporta el efecto real**:

- la fila de `whatsapp_webhook_events` (por `idempotency_key`): `processed_at`, `outcome`, `error`
- el `AlertRecipient` con ese `provider_message_id`: `channel`, `status`, `delivered_at`,
  `failure_reason`
- los recipients hermanos de fallback: `fallback → email: pending`
- con `--event=received` no hay recipient que mirar (el `wamid` de un mensaje entrante es del
  mensaje que mandó el cliente, no de uno nuestro): en su lugar reporta la fila de `opt_outs`
  para el `--from=` normalizado, como `estado: dado de baja` o `estado: sin baja registrada`

Escenarios que conviene ejercitar:

```bash
# 1. Progresión normal de estados
php artisan kapso:simulate-webhook <wamid> --event=sent
php artisan kapso:simulate-webhook <wamid> --event=delivered
php artisan kapso:simulate-webhook <wamid> --event=read

# 2. Fallo → debe disparar el fallback a email
php artisan kapso:simulate-webhook <wamid> --event=failed --code=131047

# 3. Idempotencia → la segunda vez debe responder {"status":"duplicate"}
php artisan kapso:simulate-webhook <wamid> --event=delivered --idempotency-key=fija-123
php artisan kapso:simulate-webhook <wamid> --event=delivered --idempotency-key=fija-123
```

Los estados no retroceden. `RecordDeliveryStatus` aplica una precedencia estricta
(`pending` 0 < `sent` 1 < `delivered` 2 < `read` 3) y un `read` seguido de un `sent` deja el
`read` intacto, reportando `ignorado: sent no supera read`.

`outcome` es un string legible que se guarda en `whatsapp_webhook_events.outcome`. Los
valores posibles:

| `outcome` | Significado |
| --- | --- |
| `aplicado: <status>` | Se actualizó el recipient |
| `sin recipient para ese message id` | El `wamid` no corresponde a ninguna entrega registrada |
| `ignorado: recipient suprimido` | Opt-out |
| `ignorado: ya entregado` | Llegó un estado anterior después del `delivered` |
| `ignorado: ya fallado` | |
| `ignorado: recipient ya fallado` | |
| `ignorado: <status> no supera <status-actual>` | Precedencia |
| `fallado, fallback disparado` | Se creó el recipient de email |

Los eventos entrantes (`whatsapp.message.received`) los interpreta `RecordInboundOptOut`, no
`RecordDeliveryStatus`, así que tienen su propio vocabulario de `outcome`:

| `outcome` | Significado |
| --- | --- |
| `opt-out registrado` | Se creó (o ya existía) la fila en `opt_outs` |
| `opt-in: baja revertida` | Se borró la fila de `opt_outs` |
| `opt-in: no había baja previa` | Palabra clave de alta sobre un teléfono sin baja: no-op |
| `mensaje entrante sin palabra clave reconocida` | Texto libre que no está en las listas de 7.7 |
| `mensaje entrante sin from/body aplicable` | Sin `message.from`, o sin `message.text.body` (imagen, sticker, botón) |

> Con `--event=sent/delivered/read` sobre un `wamid` de `kapso:send-test` vas a ver
> `sin recipient para ese message id`. **Es correcto**: ese comando no crea `AlertRecipient`.
> Para ver el circuito completo necesitás una `Alert` real que haya pasado por
> `DeliverAlertJob`.

### 7.7 Opt-out entrante (bajas por WhatsApp)

Kapso es el único proveedor por el que llegan mensajes del destinatario, y por eso es el único
camino por el que hoy se puede originar una baja. El circuito es:

```
El destinatario responde "BAJA" por WhatsApp
   │
   ▼
whatsapp.message.received  → POST /api/v1/webhooks/kapso
   │
   ▼
ProcessWhatsappWebhookEventJob rama por event_type
   │
   ▼
RecordInboundOptOut → escribe (o borra) la fila en opt_outs
   │
   ▼
OptOutPolicy suprime los envíos futuros de WhatsApp a ese teléfono
```

#### Paso obligatorio del operador

**Sin re-registrar el webhook, este bloque entrega cero eventos entrantes.** La lista de eventos
suscriptos vive en la plataforma de Kapso, fijada al momento del registro: **no está en el
repo**, así que desplegar el código no la cambia. Un webhook registrado antes de este cambio
sigue suscripto a los cuatro eventos de estado y ningún mensaje entrante va a llegar nunca.

Primero el ensayo, para confirmar que aparecen los **cinco** eventos en el payload:

```bash
php artisan kapso:register-webhook https://xxxx.trycloudflare.com/api/v1/webhooks/kapso --update --dry-run
```

Y después de verdad:

```bash
php artisan kapso:register-webhook https://xxxx.trycloudflare.com/api/v1/webhooks/kapso --update
```

#### Qué palabras reconoce

`RecordInboundOptOut` normaliza el cuerpo del mensaje y lo compara por **igualdad exacta**
contra dos listas cerradas:

| Acción | Palabras reconocidas |
| --- | --- |
| Baja (crea la fila en `opt_outs`) | `baja`, `stop`, `cancelar`, `desuscribir`, `desuscribirme` |
| Alta (borra la fila de `opt_outs`) | `alta`, `start`, `suscribir`, `suscribirme` |

La normalización previa a la comparación hace, en este orden: recorta espacios, pasa a
minúsculas, quita acentos (`Str::ascii()`) y recorta los signos de puntuación de los extremos
(`. , ! ¡ ? ¿`). Así que `"Baja"`, `" BAJA "`, `"¡baja!"` y `"BAJÁ."` todas matchean.

**La comparación NO es por substring, y es deliberado.** Una frase que no esté en la lista **no
da de baja a nadie**: `"ya no quiero mas mensajes de baja de peso del ganado"` contiene la
palabra `baja` y se ignora (queda registrada con `outcome` =
`mensaje entrante sin palabra clave reconocida`). El razonamiento está en la entrada de
Troubleshooting "Alguien pidió la baja con una frase y no se dio de baja".

Otros detalles del comportamiento, todos verificables en el `outcome` del evento:

- La baja es **idempotente** (`firstOrCreate`): repetir `BAJA` no duplica la fila.
- El alta **borra** la fila. Un alta sobre un teléfono sin baja previa es un no-op, no un error.
- La baja es por `(phone, channel)` con `channel = whatsapp`, **sin scoping por veterinaria**:
  suprime los envíos de WhatsApp de todas las vets, y también los que salen por Twilio.
- El teléfono se guarda normalizado a solo dígitos, tal como lo reporta Meta, **conservando el
  `9` de los móviles argentinos** (`5491134290838`). El mutator de `OptOut::phone` aplica esa
  normalización en cualquier escritura, venga del webhook, de un seeder o de `tinker`.
- Los mensajes sin texto (imagen, sticker, respuesta de botón) no tienen `message.text.body`:
  se ignoran sin error, y son la mayoría del tráfico entrante.

#### Probarlo local sin esperar un WhatsApp real

```bash
php artisan kapso:simulate-webhook wamid.HBgLNTQ5... --event=received --from=5491134290838 --text=BAJA
```

El comando reporta después del POST la fila de `whatsapp_webhook_events` y el estado en
`opt_outs` para ese teléfono. Escenarios que conviene ejercitar:

```bash
# 1. Baja
php artisan kapso:simulate-webhook <wamid> --event=received --from=5491134290838 --text=BAJA

# 2. Repetirla → sigue habiendo una sola fila (outcome: opt-out registrado)
php artisan kapso:simulate-webhook <wamid> --event=received --from=5491134290838 --text=BAJA

# 3. Alta → revierte la baja
php artisan kapso:simulate-webhook <wamid> --event=received --from=5491134290838 --text=ALTA

# 4. Frase libre → NO da de baja (outcome: sin palabra clave reconocida)
php artisan kapso:simulate-webhook <wamid> --event=received --from=5491134290838 --text="sacame de la lista"
```

Verificación en la base:

```sql
SELECT id, phone, channel, created_at FROM opt_outs ORDER BY id DESC LIMIT 10;
```

Para ver la supresión de punta a punta: con la fila de `opt_outs` puesta, generar una alerta
nueva para un contacto cuyo teléfono normalice a ese mismo valor y correr
`alerts:dispatch-due`. El recipient tiene que quedar en `suppressed`, no en `sent`.

> **El formato tiene que coincidir exactamente.** `OptOutPolicy` compara con un `=` de SQL, y
> del lado de salida el teléfono del contacto se normaliza con la misma función. Si un contacto
> está guardado con un valor que no normaliza al mismo string que reporta Meta, la baja existe
> en la tabla y no suprime nada, en silencio.

---

## 8. Paso 5B — Setup de Twilio

### 8.1 Credenciales

```env
WHATSAPP_PROVIDER=twilio
TWILIO_ACCOUNT_SID=<sid>
TWILIO_AUTH_TOKEN=<token>
TWILIO_TEMPLATE_MESSAGE_SERVICE=<messaging-service-sid>
```

```bash
php artisan config:clear
```

### 8.2 Crear los Content templates

```bash
php artisan twilio:create-templates --dry-run
```

| Opción | Default | Qué hace |
| --- | --- | --- |
| `--language=` | `es` | Código de idioma del template |
| `--dry-run` | — | Muestra el payload sin crear nada |

Sin `--dry-run`, hace `POST https://content.twilio.com/v1/Content` con basic auth
(SID + token) por cada uno de los tres templates y **te imprime las líneas listas para pegar
en el `.env`**:

```
program.created → HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
program.cancelled → HXyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
program.task_due → HXzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz

Pegá esto en tu .env:
TWILIO_TEMPLATE_PROGRAM_CREATED=HXxxxx...
TWILIO_TEMPLATE_PROGRAM_CANCELLED=HXyyyy...
TWILIO_TEMPLATE_PROGRAM_TASK_DUE=HXzzzz...

Después: php artisan config:clear
```

Pegar, `php artisan config:clear`, listo.

### 8.3 Webhook de estados de entrega

El endpoint es `POST /api/v1/webhooks/twilio` (nombre de ruta `webhooks.twilio`, middleware
`twilio.signature`), y funciona igual que el de Kapso: persiste el evento en
`whatsapp_webhook_events` y despacha `ProcessWhatsappWebhookEventJob`, que aplica el estado con
el **mismo** `RecordDeliveryStatus` — misma precedencia monótona, mismo fallback a email.

#### No hay paso manual en la consola de Twilio

`TwilioWhatsappGateway` manda el parámetro `statusCallback` en **cada envío**, así que no hace
falta configurar a mano la "Status Callback URL" del Messaging Service. La URL la resuelve
`NotificationServiceProvider`:

1. `TWILIO_STATUS_CALLBACK_URL` si está seteada.
2. Si está vacía, `route('webhooks.twilio')` — o sea `APP_URL` + `/api/v1/webhooks/twilio`.

Lo único que hay que garantizar es que esa URL sea **HTTPS pública y alcanzable por Twilio**.
En local, eso pide el mismo tipo de túnel que ya se usa para Kapso:

```bash
cloudflared tunnel --url http://localhost:8000
```

```env
TWILIO_STATUS_CALLBACK_URL=https://xxxx.trycloudflare.com/api/v1/webhooks/twilio
```

```bash
php artisan config:clear
```

Verificar que la ruta esté registrada:

```bash
php artisan route:list --path=webhooks
```

> Si en el pasado alguien configuró una "Status Callback URL" a mano en el Messaging Service,
> puede quedar como está: Twilio usa el `statusCallback` explícito del request cuando está
> presente. Limpiarla evita confusión operativa, nada más.

#### La firma

Nada que ver con la de Kapso. Twilio firma **HMAC-SHA1 en base64 sobre la URL completa que
llama, más todos los parámetros POST ordenados por clave**, y la manda en el header
`X-Twilio-Signature`. El secreto es el `TWILIO_AUTH_TOKEN` de la cuenta — no hay un secreto
propio del webhook como en Kapso. La verificación usa `Twilio\Security\RequestValidator`, el
código del propio SDK, en lugar de una reimplementación a mano.

El middleware **falla cerrado**: si `TWILIO_AUTH_TOKEN` está vacío, el endpoint responde
`500 TWILIO_AUTH_TOKEN no configurado.` Con firma inválida o ausente,
`401 Firma de webhook de Twilio inválida.`

> **Que la URL coincida byte a byte es la parte frágil.** Es una diferencia real con Kapso: allá
> se firma el body, acá se firma la URL. Detrás de un túnel esto se rompe de una forma poco
> obvia — leer la entrada "El webhook de Twilio responde 401" en Troubleshooting **antes** de
> perder una tarde con el auth token.

#### Cómo se guarda cada evento

| Columna | Valor |
| --- | --- |
| `provider` | `twilio` |
| `idempotency_key` | `twilio:{MessageSid}:{MessageStatus}` |
| `event_type` | `twilio.message.{MessageStatus}` |
| `provider_message_id` | el `MessageSid` |
| `payload` | reshapeado al mismo shape anidado de Kapso (`message.id`, `message.errors.0.{code,title}`) |

Twilio no manda ningún header de idempotencia, así que la clave se deriva del par
`(MessageSid, MessageStatus)`: un reintento del mismo evento produce la misma clave y se
deduplica por el índice único, igual que en Kapso.

El endpoint responde **`204 No Content` en todos los casos aceptados** — evento nuevo,
duplicado, o payload sin `MessageSid`/`MessageStatus` reconocible. Es deliberado: un `4xx`/`5xx`
acá solo dispararía la cadena de reintentos de Twilio sin arreglar nada de este lado.

El vocabulario de `MessageStatus` de Twilio se traduce a los mismos `DeliveryStatus` que usa
Kapso:

| `MessageStatus` de Twilio | `event_type` guardado | `DeliveryStatus` resultante |
| --- | --- | --- |
| `queued` | `twilio.message.queued` | `sent` |
| `sending` | `twilio.message.sending` | `sent` |
| `sent` | `twilio.message.sent` | `sent` |
| `delivered` | `twilio.message.delivered` | `delivered` |
| `read` | `twilio.message.read` | `read` |
| `failed` | `twilio.message.failed` | `failed` |
| `undelivered` | `twilio.message.undelivered` | `failed` |

`queued` y `sending` significan solamente "el proveedor lo aceptó", lo mismo que `sent`. El
prefijo `twilio.message.*` se conserva a propósito (en lugar de relabelarlo a
`whatsapp.message.*`): hace que un `SELECT event_type FROM whatsapp_webhook_events` diga de qué
proveedor vino cada fila sin tener que mirar también la columna `provider`.

Un `MessageStatus` que no esté en esa tabla cae en `UnsupportedWebhookEventException`: el evento
se cierra con el error guardado y **no** reintenta al vacío.

### 8.4 Verificar el circuito de estados sin esperar a Twilio

Mismo patrón que `kapso:simulate-webhook`: firma un POST contra tu propio endpoint y después
lee la base para reportar el efecto real.

```bash
php artisan twilio:simulate-webhook SM1234567890abcdef --status=delivered
```

| Argumento / Opción | Default | Qué hace |
| --- | --- | --- |
| `sid` | requerido | El `MessageSid` de un envío real (`provider_message_id` del recipient) |
| `--status=` | `delivered` | `queued` \| `sending` \| `sent` \| `delivered` \| `read` \| `failed` \| `undelivered` |
| `--error-code=` | — | Código de error de Twilio, solo con `--status=failed\|undelivered` |
| `--error-message=` | — | Detalle del error, solo con `--status=failed\|undelivered` |
| `--to=` | `whatsapp:+5490000000000` | Destino en formato `whatsapp:+E.164`. Solo informativo en el payload |
| `--url=` | ruta local `webhooks.twilio` | Endpoint destino |

Postea `application/x-www-form-urlencoded` con los campos estándar de un Status Callback
(`MessageSid`, `MessageStatus`, `To`, `From`, y `ErrorCode`/`ErrorMessage` cuando se pasan), y
firma con `RequestValidator::computeSignature()` — el mismo código del SDK que corre la
validación del otro lado, así que las dos puntas nunca pueden divergir por una
reimplementación.

Exige `TWILIO_AUTH_TOKEN`: sin él no hay con qué firmar y el comando aborta.

Después del POST reporta:

- la fila de `whatsapp_webhook_events` (por `idempotency_key`): `provider`, `event_type`,
  `processed_at`, `outcome`, `error`
- el `AlertRecipient` con ese `provider_message_id`: `channel`, `status`, `delivered_at`,
  `failure_reason`
- los recipients hermanos de fallback: `fallback → email: pending`

Escenarios que conviene ejercitar:

```bash
# 1. Progresión normal de estados
php artisan twilio:simulate-webhook <sid> --status=sent
php artisan twilio:simulate-webhook <sid> --status=delivered
php artisan twilio:simulate-webhook <sid> --status=read

# 2. Fallo → debe disparar el fallback a email
php artisan twilio:simulate-webhook <sid> --status=failed --error-code=63016 --error-message="Failed to send freeform message"

# 3. Idempotencia → la segunda vez no crea una fila nueva
php artisan twilio:simulate-webhook <sid> --status=delivered
php artisan twilio:simulate-webhook <sid> --status=delivered
```

Los `outcome` son exactamente los mismos que la tabla de 7.6: es el mismo
`RecordDeliveryStatus`. Un `sid` que no salga de un envío real de esta base va a reportar
`sin recipient para ese message id`, y **es correcto**.

> A diferencia de Kapso, acá la deduplicación no se controla con una opción: la clave se deriva
> de `(sid, status)`. Repetir el mismo comando con el mismo `--status=` es exactamente la prueba
> de idempotencia; para forzar una fila nueva hay que cambiar el `--status=` o el `sid`.

### 8.5 Limitaciones conocidas de Twilio en este proyecto

Documentadas para que no las descubras debuggeando:

- **No hay comando de envío real de prueba para Twilio.** El único `send-test` es de Kapso
  (`kapso:send-test`). `twilio:simulate-webhook` simula un webhook *entrante*, no un envío: para
  obtener un `MessageSid` real hay que hacer pasar una `Alert` por el `DeliverAlertJob`.
- **Por Twilio no llegan mensajes entrantes**, así que las bajas solo se pueden originar por
  Kapso (ver 7.7). Una baja registrada ahí sí suprime los envíos por Twilio, porque `opt_outs`
  se indexa por `(phone, channel)` y el canal es `whatsapp` para ambos proveedores.
- `TWILIO_TEMPLATE_PROGRAM_TASK_DUE` está pendiente de aprobación comercial: el camino
  `program.task_due` sobre Twilio es hoy inutilizable.
- El gateway de Twilio no tiene timeout configurable. `TwilioWhatsappGateway` recibe un
  `Twilio\Rest\Client` construido sin opciones de timeout, y no hay una `TWILIO_TIMEOUT`
  equivalente a `KAPSO_TIMEOUT`.
- La validación de firma depende de que `$request->fullUrl()` resuelva exactamente a la URL que
  Twilio firmó, y **este proyecto no tiene `TrustProxies` configurado** — ver Troubleshooting.

---

## 9. Paso 6 — Verificación end-to-end

Checklist para dar el setup por bueno.

```bash
# 1. El provider elegido se lee bien (no debe tirar excepción)
php artisan tinker --execute="echo config('notifications.channels.whatsapp.gateway');"

# 2. Los comandos existen y están registrados
php artisan list kapso
php artisan list twilio
php artisan list alerts

# 3. El scheduler tiene la tarea
php artisan schedule:list

# 4. Las dos rutas de webhook existen: deben aparecer webhooks.kapso Y webhooks.twilio
php artisan route:list --path=webhooks

# 5. La suite de tests pasa
php artisan test --filter=Notification
php artisan test --filter=Kapso
```

Para el circuito real de una alerta:

1. Generar una `Alert` con `scheduled_at <= now()` y al menos un `AlertRecipient` en `pending`
   (vía seeder o el flujo de la app).
2. `php artisan alerts:dispatch-due` → la alerta pasa a `dispatched`.
3. Con el worker corriendo, el `DeliverAlertJob` envía y deja el recipient en `sent` con un
   `provider_message_id`.
4. Simular el estado según el proveedor que estés usando → el recipient pasa a `delivered` con
   `delivered_at`:

   ```bash
   # Kapso
   php artisan kapso:simulate-webhook <ese-provider-message-id> --event=delivered

   # Twilio
   php artisan twilio:simulate-webhook <ese-provider-message-id> --status=delivered
   ```

5. Repetir con `--event=failed` / `--status=failed` en otra alerta para ver aparecer el
   recipient de email.
6. Para el opt-out entrante (solo Kapso):
   `php artisan kapso:simulate-webhook <wamid> --event=received --from=<telefono> --text=BAJA`
   → tiene que aparecer la fila en `opt_outs`, y una alerta posterior a ese mismo teléfono
   tiene que quedar `suppressed`.

Consultas útiles:

```sql
SELECT id, type, status, scheduled_at FROM alerts ORDER BY id DESC LIMIT 10;

SELECT id, alert_id, channel, status, provider_message_id, attempts, failure_reason
FROM alert_recipients ORDER BY id DESC LIMIT 20;

SELECT id, provider, event_type, provider_message_id, processed_at, outcome, error
FROM whatsapp_webhook_events ORDER BY id DESC LIMIT 20;

SELECT id, phone, channel, created_at FROM opt_outs ORDER BY id DESC LIMIT 10;

SELECT * FROM failed_jobs ORDER BY id DESC LIMIT 10;
```

---

## 10. Referencia rápida de comandos

| Comando | Red | DB | Para qué |
| --- | --- | --- | --- |
| `kapso:create-templates [--dry-run] [--category=] [--business-account-id=]` | escribe | no | Provisionar templates en Meta vía Kapso |
| `kapso:register-webhook <url> [--update] [--id=] [--dry-run]` | escribe | no | Registrar / re-apuntar el webhook. Suscribe **cinco** eventos: los cuatro de estado + `whatsapp.message.received`. **Obligatorio con `--update` para que lleguen las bajas** |
| `kapso:send-test <phone> [--type=] [--text=] [--name=]` | **envía WhatsApp real** | no | Probar credenciales y templates, obtener el `wamid` |
| `kapso:simulate-webhook <wamid> [--event=] [--code=] [--title=] [--from=] [--text=] [--idempotency-key=] [--url=]` | POST a tu app | escribe (indirecto) | Probar el circuito de estados y el fallback, y con `--event=received` el opt-out entrante |
| `twilio:create-templates [--language=] [--dry-run]` | escribe | no | Provisionar Content templates y obtener los `contentSid` |
| `twilio:simulate-webhook <sid> [--status=] [--error-code=] [--error-message=] [--to=] [--url=]` | POST a tu app | escribe (indirecto) | Probar el circuito de estados de Twilio y el fallback |
| `alerts:dispatch-due` | no | escribe | Fan-out manual de alertas vencidas |
| `queue:work` | — | escribe | Procesar los jobs |
| `schedule:work` | — | escribe | Correr `alerts:dispatch-due` cada minuto |
| `config:clear` | — | no | **Después de cualquier cambio en `.env`** |

---

## 11. Troubleshooting

### `WHATSAPP_PROVIDER inválido: 'xxx'. Disponibles: twilio, kapso, fake`

Typo en la variable. Valores válidos: `twilio`, `kapso`, `fake`. Corregir y
`php artisan config:clear`.

El error aparece al resolver el canal, no al cargar la configuración: el resto de la
aplicación sigue funcionando y la alerta se entrega por el fallback de email. Si viste este
mismo problema como un 500 global en todos los endpoints, era el comportamiento anterior —
ya está corregido.

### Cambié el `.env` y no pasa nada

`php artisan config:clear`. Si alguna vez corriste `config:cache`, el `.env` no se lee hasta
que limpies.

### El webhook de Kapso responde 401

`Firma de webhook inválida.` — el `KAPSO_WEBHOOK_SECRET` de la app que atiende el request no
es el mismo que se usó para firmar. Chequeá tres cosas:

1. Que el `.env` de la app que responde tenga el secreto correcto.
2. Que hayas corrido `config:clear` después de escribirlo.
3. Que el secreto registrado en Kapso sea ese mismo (`kapso:register-webhook` avisa si no).

La firma es HMAC-SHA256 hexadecimal sobre el **cuerpo crudo**, sin prefijo `sha256=`, en el
header `X-Webhook-Signature`.

### El webhook de Kapso responde 500 `KAPSO_WEBHOOK_SECRET no configurado`

La variable está vacía. El middleware falla cerrado a propósito.

### El webhook de Twilio responde 401

`Firma de webhook de Twilio inválida.` — y acá el diagnóstico obvio es casi siempre el
equivocado, así que leelo entero antes de tocar el `TWILIO_AUTH_TOKEN`.

Twilio no firma el body: firma **la URL exacta que llama**, más los parámetros POST ordenados
por clave. Del lado nuestro, `VerifyTwilioWebhookSignature` compara contra
`$request->fullUrl()`. Si esas dos URLs no son idénticas, la firma no coincide **para el 100% de
los eventos**, con un token perfectamente válido.

El problema de origen, y por qué el síntoma engaña: detrás de un proxy que termina TLS
(`cloudflared tunnel --url http://localhost:8000`, un balanceador, Cloudflare), Twilio llama y
firma `https://…` mientras el origen local recibe `http://…`. El `RequestValidator` del SDK
normaliza el **puerto** (prueba con y sin), pero **no el esquema** — verificado en
`vendor/twilio/sdk/src/Twilio/Security/RequestValidator.php`, donde `validate()` compara
`addPort()` y `removePort()` y nada más. Resultado: 401 permanente con un token perfecto.

**Ya está resuelto por dos mecanismos complementarios. Si ves este 401, revisá los dos.**

1. **`bootstrap/app.php` declara `$middleware->trustProxies(at: '*')`**, así Laravel respeta
   `X-Forwarded-Proto` y `fullUrl()` devuelve el esquema real. ⚠️ **En producción hay que
   reemplazar el `'*'` por los rangos de IP del proxy o balanceador real.** Confiar en cualquier
   proxy solo es seguro mientras la app no sea alcanzable directamente: un cliente que la alcance
   salteando el proxy puede falsear `X-Forwarded-*`, y eso incluye la IP de origen, que es la
   clave del rate limiter de login.
2. **`TWILIO_STATUS_CALLBACK_URL`, si está seteada, gana sobre `fullUrl()`** para calcular la
   firma. Es la misma URL que se le pasa a Twilio como `statusCallback`, así que por definición es
   la que Twilio firmó. Sirve como red de seguridad cuando el punto 1 no alcanza (túneles rápidos)
   y hace el chequeo independiente de cómo el proxy presente el request.

   Contrapartida: si esa variable apunta a una URL vieja o con una barra final de diferencia, el
   síntoma vuelve a ser un 401. Cuando rote el túnel, actualizala junto con
   `kapso:register-webhook --update`.

Antes de asumir que es esto, descartá lo simple:

1. Que el `TWILIO_AUTH_TOKEN` de la app que responde sea el de la misma cuenta que envió.
2. Que hayas corrido `config:clear`.
3. Que `TWILIO_STATUS_CALLBACK_URL` (o `APP_URL`) sea exactamente la URL pública del túnel
   vigente, sin barra final de más ni menos.

`twilio:simulate-webhook` no reproduce el problema por sí solo cuando corre contra la ruta local
(firma y verifica la misma URL), pero sí lo reproduce si le pasás `--url=` con un esquema
distinto al que Laravel resuelve — es la forma más rápida de confirmar que el 401 es de esquema
y no de token.

### El webhook de Twilio responde 500 `TWILIO_AUTH_TOKEN no configurado`

La variable está vacía. El middleware falla cerrado a propósito: aceptar webhooks sin verificar
sería peor que rechazarlos.

### Las bajas no llegan (nadie se puede dar de baja por WhatsApp)

Lo primero a chequear no es el código: **es la suscripción del webhook en Kapso.** La lista de
eventos suscriptos vive en la plataforma de Kapso, fijada al momento del registro, no en el
repo. Un webhook registrado antes de que existiera el opt-out entrante sigue suscripto solo a
los cuatro eventos de estado, así que **ningún `whatsapp.message.received` llega nunca**, por
más que el código esté desplegado.

```bash
# Confirmar que aparecen los CINCO eventos
php artisan kapso:register-webhook <url-publica> --update --dry-run

# Aplicarlo
php artisan kapso:register-webhook <url-publica> --update
```

Si después de eso todavía no llegan, en orden:

1. Simular el evento localmente para descartar que el problema esté de este lado:
   `php artisan kapso:simulate-webhook <wamid> --event=received --from=<telefono> --text=BAJA`.
   Si eso escribe en `opt_outs`, el pipeline está bien y el problema es la entrega de Kapso.
2. Revisar el `outcome` de la fila en `whatsapp_webhook_events`: si dice
   `mensaje entrante sin palabra clave reconocida`, el evento llegó y la frase no estaba en la
   lista (ver la entrada siguiente).
3. Revisar que el worker esté procesando `ProcessWhatsappWebhookEventJob`. Mirá `failed_jobs`.

### Alguien pidió la baja con una frase y no se dio de baja

**Es el comportamiento esperado, por diseño.** El reconocimiento es por igualdad exacta contra
una lista cerrada de palabras (ver 7.7), no por substring: `"sacame de la lista"` o
`"ya no quiero mas mensajes"` no dan de baja a nadie, y el evento queda registrado con
`outcome` = `mensaje entrante sin palabra clave reconocida`.

El razonamiento es asimétrico a propósito. Un **falso positivo** —dar de baja a alguien que no
lo pidió, porque su mensaje contenía la palabra `baja` en otro sentido ("mensajes de baja de peso
del ganado")— es un fallo de entrega silencioso e indetectable: nadie se entera nunca de que esa
persona dejó de recibir alertas. Un **falso negativo** es, en el peor caso, un mensaje sin
respuesta que una persona de soporte puede resolver a mano. Entre los dos, se elige siempre el
segundo.

Si el negocio identifica frases de uso frecuente que valga la pena reconocer, se agregan a las
constantes `OPT_OUT_KEYWORDS`/`OPT_IN_KEYWORDS` de
`back/app/Notifications/Services/RecordInboundOptOut.php`. No hay fuzzy matching ni aprendizaje
automático, y no es un olvido.

Mientras tanto, la baja se puede aplicar a mano insertando la fila en `opt_outs` con el teléfono
normalizado a solo dígitos (el mutator del modelo lo normaliza igual si se hace vía Eloquent).

### Las alertas se quedan en `pending` para siempre

Falta el scheduler, el worker, o los dos. Levantá `php artisan schedule:work` y
`php artisan queue:work`. Para descartar el scheduler, corré `php artisan alerts:dispatch-due`
a mano.

### `TemplateNotConfiguredException`

- **Twilio**: falta el `contentSid` en `TWILIO_TEMPLATE_*`. Corré `twilio:create-templates`.
- **Kapso**: el nombre del template está vacío. Revisá `KAPSO_TEMPLATE_*`.

Es un error **definitivo**: el job no reintenta y dispara el fallback.

### `Falta KAPSO_API_KEY` / `Falta KAPSO_PHONE_NUMBER_ID`

Faltan credenciales. `KAPSO_PHONE_NUMBER_ID` se valida al construir el gateway porque forma
parte de la URL de envío.

### El WhatsApp se envía pero el recipient nunca pasa a `delivered`

Cuatro causas posibles, en orden de probabilidad:

1. **El webhook del proveedor no está llegando.** En Kapso: no está registrado, o apunta a un
   túnel que ya murió — re-apuntalo con `kapso:register-webhook <nueva-url> --update`. En Twilio:
   revisá que el `statusCallback` sea alcanzable y que no esté rebotando con 401 (ver la entrada
   del 401 de Twilio más arriba, que es la causa más común y la más engañosa).
2. **El worker no está procesando `ProcessWhatsappWebhookEventJob`.** Mirá `failed_jobs`.
3. **El evento llegó pero no se pudo correlacionar.** Revisá
   `whatsapp_webhook_events.outcome`: `sin recipient para ese message id` significa que el
   `provider_message_id` del webhook no coincide con ningún `alert_recipients`.
4. **Es un recipient de email.** `MailGateway` devuelve un UUID sintético como
   `provider_message_id`, así que una entrega por email nunca recibe confirmación de webhook y se
   queda en `sent` por diseño.

### `sin recipient para ese message id` al simular un webhook

El `wamid` no corresponde a ningún `AlertRecipient`. Si viene de `kapso:send-test`, es
correcto: ese comando no crea registros en la base.

### El recipient va directo a `failed` sin intentar enviar

Muy probablemente `RecipientContactNotFoundException`. El contacto necesita
**`contacts.use_for_alerts = true`**, y la columna es `boolean default false`.

Eso **no es un bug, es un opt-in deliberado**: `ContactService` hace
`$data['use_for_alerts'] ?? false` y los form requests lo validan como `nullable|boolean`.
Un contacto no recibe alertas salvo que alguien lo marque explícitamente. No cambies el
default de la migración: mandarías mensajes a gente que nunca los pidió.

`TestDataSeeder` ya crea, por cada perfil sembrado, un contacto WhatsApp y uno Email con
`use_for_alerts = true` y teléfonos obviamente falsos (`549110000NNNN`), así que después de
`php artisan migrate:fresh --seed` el pipeline es ejercitable sin tocar nada. Si estás
trabajando con datos propios, marcá el contacto a mano.

### Un fallo de WhatsApp genera un segundo fallo en email

Casi siempre `MAIL_MAILER=smtp` sin nada escuchando en `MAIL_PORT`. Pasá a
`MAIL_MAILER=log` o levantá Mailpit. `MailGateway` propaga toda excepción, así que el job
quema sus 5 reintentos.

### Los tests se comportan distinto en mi máquina que en CI

`phpunit.xml` fija `WHATSAPP_PROVIDER=fake`, además de `MAIL_MAILER=array`,
`QUEUE_CONNECTION=sync`, `DB_CONNECTION=sqlite` y `DB_DATABASE=:memory:`. La suite ya **no**
lee el provider de tu `.env`, así que local y CI resuelven el mismo gateway.

Si necesitás ejercitar un gateway real en un test, resolvelo explícitamente en el test (como
hacen `KapsoWhatsappGatewayTest` y `TwilioWhatsappGatewayTest`, que construyen el gateway a
mano con un cliente mockeado) en lugar de depender de la configuración del entorno.

### `Channel::Sms` o `Channel::Push` tiran `Sin gateway para canal sms`

No están implementados. Existen en el enum `Channel` pero no tienen entrada en
`config('notifications.channels')`. Lo mismo con `AlertType::HealthPlanMonth` y
`AlertType::EventReminder`: no tienen message builder registrado y fallan en
`MessageBuilderRegistry::for()`.

---

## 12. Anexo — Tablas y archivos de referencia

### Tablas

| Tabla | Para qué | Columnas clave |
| --- | --- | --- |
| `alerts` | La alerta lógica | `guid`, `type`, `subject_type`/`subject_id`, `payload`, `scheduled_at`, `status`, `require_confirmation`, `vet_id` |
| `alert_recipients` | Una entrega por destinatario y canal | `guid`, `alert_id`, `user_profile_id`, `channel`, `status`, `provider_message_id` (indexado), `attempts`, `failure_reason`, `sent_at`, `delivered_at`, `confirmed_at`, `idempotency_key` |
| `opt_outs` | Bajas por teléfono y canal | `phone` (E.164 sin `+`), `channel`, único por `(phone, channel)` |
| `whatsapp_webhook_events` | Webhooks recibidos, con dedupe | `provider` (**sin default**), `idempotency_key` (único), `event_type`, `provider_message_id`, `payload`, `payload_version`, `processed_at`, `outcome`, `error` |

`alert_recipients` tiene un único compuesto en `(alert_id, user_profile_id, channel)`. Ojo:
la tabla se referencia por **`user_profile_id`**, no por `user_id` (el doc viejo
`docs/planes/arquitectura-notificaciones.md` dice `user_id` y está desactualizado en eso).

Sobre el ciclo de confirmación, con precisión porque es fácil equivocarse:

- **`require_confirmation` NO está en `alert_recipients`.** Vive en `alerts` y en
  `protocol_task_alerts`. Se escribe correctamente (`GenerateProgramTaskDueAlertsListener` la
  copia del protocolo a la alerta), pero **nadie la lee nunca**: ni el job, ni los builders,
  ni las políticas, ni los resources.
- **`alert_recipients.confirmed_at` sí existe y está completamente muerta**: solo aparece en
  `$fillable`, en los casts y en la migración. Ninguna asignación, ninguna query, ningún test.

Consumir mensajes entrantes para cerrar el ciclo está pendiente, así que la confirmación es
100% greenfield: el flag se guarda y la columna está lista, sin nada conectado.

### Archivos

| Archivo | Qué contiene |
| --- | --- |
| `back/config/notifications.php` | Toda la configuración del subsistema |
| `back/app/Notifications/NotificationServiceProvider.php` | Bindings de gateways, builders y políticas |
| `back/app/Notifications/Registries/GatewayRegistry.php` | Selección de gateway por canal |
| `back/app/Notifications/Gateways/Kapso/KapsoWhatsappGateway.php` | Envío por Kapso |
| `back/app/Notifications/Gateways/Twilio/TwilioWhatsappGateway.php` | Envío por Twilio |
| `back/app/Notifications/Gateways/Mail/MailGateway.php` | Envío por email |
| `back/app/Notifications/Gateways/Fake/FakeGateway.php` | Gateway de desarrollo y tests |
| `back/app/Notifications/Jobs/DeliverAlertJob.php` | Job de entrega, reintentos y fallback |
| `back/app/Notifications/Jobs/ProcessWhatsappWebhookEventJob.php` | Job de procesamiento de webhooks, con la rama entrante vs. estado de entrega |
| `back/app/Notifications/Services/RecordDeliveryStatus.php` | Precedencia de estados y `outcome`. Mapea claves `whatsapp.message.*` (Kapso) y `twilio.message.*` |
| `back/app/Notifications/Services/RecordInboundOptOut.php` | Interpreta mensajes entrantes y escribe/borra `opt_outs` |
| `back/app/Notifications/Services/ChannelFallbackService.php` | Creación del recipient de fallback |
| `back/app/Notifications/Support/PhoneNumber.php` | Normalizador único de teléfonos, compartido por envío y opt-out |
| `back/app/Notifications/Http/Controllers/KapsoWebhookController.php` | Persistencia idempotente del webhook de Kapso |
| `back/app/Notifications/Http/Controllers/TwilioWebhookController.php` | Idem Twilio; reformatea el payload plano a la forma anidada de Kapso |
| `back/app/Notifications/Http/Middleware/VerifyKapsoWebhookSignature.php` | HMAC-SHA256 sobre el body crudo (alias `kapso.signature`) |
| `back/app/Notifications/Http/Middleware/VerifyTwilioWebhookSignature.php` | HMAC-SHA1 sobre URL + params vía `Twilio\Security\RequestValidator` (alias `twilio.signature`) |
| `back/routes/api/notifications-webhooks.php` | `POST /api/v1/webhooks/kapso` y `POST /api/v1/webhooks/twilio` |
| `back/routes/console.php` | `Schedule::command('alerts:dispatch-due')->everyMinute()` |
| `back/bootstrap/app.php` | `trustProxies`, alias `kapso.signature` y `twilio.signature`, registro de `DispatchDueAlerts` |
| `back/app/Mail/AlertMail.php` | Mailable del canal email |

### Deuda conocida y abierta

Si te topás con alguna de estas, no es tu setup.

**TKT-006 está implementado**: Twilio tiene webhook de estados y el opt-out entrante por Kapso
escribe `opt_outs`. Lo que dejó abierto:

- **El opt-in no deja rastro.** Un `alta` **borra** la fila de `opt_outs`, y la tabla solo tiene
  `created_at` (el modelo declara `const UPDATED_AT = null`). Después de que alguien se vuelve a
  suscribir, **no queda ningún registro de que había pedido la baja**. Para una feature cuya
  motivación es cumplimiento legal, eso es un problema: si mañana alguien reclama que pidió la
  baja en una fecha y le siguieron llegando mensajes, no hay nada que consultar. Se resuelve con
  soft deletes en `opt_outs` o con una tabla de eventos de consentimiento append-only — requiere
  cambio de esquema, así que quedó fuera de esta iteración.
- **`trustProxies` está en `'*'`.** Funciona, pero en producción hay que acotarlo a los rangos del
  proxy real (ver la entrada del 401 de Twilio en la sección 11 para el porqué).
- **El opt-out de email sigue siendo irrepresentable.** `opt_outs.phone` es `NOT NULL` y un
  recipient de email tiene `phone = null`, así que la consulta se convierte en un `whereNull` que
  nunca matchea. Requiere cambiar el shape de la tabla.
- **`opt_outs` no tiene scoping por veterinaria.** Una baja aplica a **todas** las vets. Es
  defendible (la baja la pide la persona, no la relación comercial), pero es una decisión de
  modelado que conviene confirmar con negocio.
- **`contacts.value` no se normaliza en escritura.** La validación E.164 ahora sí cubre los
  endpoints anidados, pero los contactos ya existentes con formatos raros siguen como están. No
  bloquea el opt-out: un contacto mal guardado tampoco se puede enviar, así que no tiene mensajes
  de los cuales darse de baja. Es deuda de calidad de datos.

Sigue abierto como ticket:

- **`.claude/docs/tickets/TKT-007-arquitectura-notificaciones-doc-desactualizado.md`** — el doc
  de arquitectura original contradice el código en varios puntos, con tabla de divergencias.

### Documentos de diseño

- `.claude/docs/plans/TKT-006-twilio-sin-webhook-ni-opt-out-plan.md` — el plan de esta
  iteración: opt-out entrante y webhook de Twilio. 15 decisiones numeradas con su justificación.
  Es la referencia vigente para entender por qué las cosas están como están.
- `.claude/docs/plans/kapso-whatsapp-provider-plan.md` — plan del proveedor Kapso. Casi todo
  implementado. **Pendiente**: `WhatsappProviderResolver` (ruteo por país), normalización de
  teléfono en escritura, y forwarding de `whatsapp.conversation.*` a soporte. Su ítem de consumir
  `whatsapp.message.received` está **parcialmente** hecho: el evento ya se consume, pero para
  opt-out, no para cerrar el ciclo de confirmación.
- `docs/planes/arquitectura-notificaciones.md` — diseño original. **Leelo como contexto
  histórico, no como referencia de configuración**, y con una advertencia concreta:

  > ⚠️ El normalizador de teléfono entrante que propone en las líneas 693-697, que quita el `9`
  > de los móviles argentinos (`549…` → `54…`), **es incorrecto y no se debe implementar.** El
  > lado de salida nunca quitó el `9`, así que aplicarlo convierte cada opt-out argentino en un
  > no-op permanente y silencioso. La forma canónica es dígitos tal como los reporta Meta, y vive
  > en `App\Notifications\Support\PhoneNumber`, que lleva esta misma advertencia en un comentario.

  Otras divergencias: menciona `user_id` en lugar de `user_profile_id`, un `VonageSmsGateway` que
  no existe, políticas `QuietHoursPolicy`/`DuplicateSuppressionPolicy`/`RateLimitPolicy` que no
  existen (solo hay `OptOutPolicy`), una tabla `user_notification_preferences` inexistente, y un
  fallback `whatsapp → ['sms', 'email']` cuando el real es `['email']`. Su `TwilioWebhookController`
  ahora **sí existe**, pero sin los eventos de dominio (`RecipientOptedOut`, etc.) que el doc
  describe: esos siguen sin implementarse.
