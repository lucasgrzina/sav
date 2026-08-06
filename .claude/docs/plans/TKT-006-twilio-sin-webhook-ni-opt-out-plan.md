# Plan técnico: Opt-out entrante por Kapso + webhook de estados de Twilio

## Input procesado

`.claude/docs/tickets/TKT-006-twilio-sin-webhook-ni-opt-out.md`. Las ocho restricciones
técnicas (R1-R8) documentadas en el ticket se toman como datos verificados, no como hipótesis a
re-investigar. Donde el diseño depende críticamente de una de ellas, se re-verificó contra el
código real (ver notas puntuales en cada decisión) y, en el caso de R7, contra la documentación
pública en vivo de Kapso.

## Resumen ejecutivo

Dos entregables, sin agregar ningún `Channel` nuevo ni tocar el ciclo de confirmación:

1. **Opt-out entrante por Kapso**: se amplía la suscripción del webhook de WhatsApp a
   `whatsapp.message.received` (verificado contra `docs.kapso.ai` — es un evento real, ver
   DEC-08), se rama la interpretación **antes** de `RecordDeliveryStatus` (R8), y un servicio
   nuevo (`RecordInboundOptOut`) reconoce palabras clave de baja/alta y escribe/borra en
   `opt_outs`, usando un normalizador de teléfono compartido nuevo (`PhoneNumber`) que
   **preserva el `9` de los móviles argentinos** — la premisa contraria, documentada en
   `docs/planes/arquitectura-notificaciones.md:693-697`, queda descartada y marcada como tal en
   el código para que nadie la reintroduzca (R1/R2).
2. **Webhook de estados de Twilio**: `TwilioWebhookController` + `VerifyTwilioWebhookSignature`
   (usa `Twilio\Security\RequestValidator`, ya presente en el SDK) + un `statusCallback`
   explícito en cada envío. Los eventos de Twilio se traducen a la MISMA tabla
   (`whatsapp_webhook_events`) y al MISMO servicio (`RecordDeliveryStatus`, apenas extendido en
   su vocabulario) que ya usa Kapso: cero duplicación de la lógica de precedencia monótona y del
   `lockForUpdate`.

Efecto colateral deliberado, ya señalado en el ticket: una baja escrita desde el webhook de
Kapso suprime también los envíos por Twilio, porque `opt_outs` se indexa por `(phone, channel)`
y el canal es `whatsapp` para ambos proveedores.

Se incluye además, porque el ticket lo exige (R3), un cierre del agujero de validación en
`contacts.*.value` para el camino anidado que alimenta `ContactService::syncContacts()` — sin
esto, un contacto mal guardado hace que el opt-out (que compara con `=` exacto) nunca pueda
matchear, aunque el resto de la feature esté bien construida.

Sin cambios de frontend, de migraciones, ni de permisos Spatie (los webhooks son callbacks de
proveedor sin usuario autenticado, igual que el de Kapso ya existente).

## Decisiones tomadas

**DEC-01 — Forma canónica del teléfono: dígitos tal como los reporta Meta, con el `9` argentino intacto**

Decisión: se formaliza en código lo que el ticket ya resolvió. `PhoneNumber::normalize()` es
`preg_replace('/\D+/', '', $value)` y nada más — no quita el `9`, no toca el código de país.

Justificación: es la identidad con la que Meta entrega el remitente real de un mensaje
entrante, y es la que ambos gateways ya usan para enviar con éxito (`KapsoWhatsappGateway`
manda `to => $phone`, `TwilioWhatsappGateway` arma `'whatsapp:+' . $phone`). Se verificó además
contra la documentación viva de Kapso (`docs.kapso.ai/docs/platform/webhooks/event-types`,
sección `whatsapp.message.received`): el campo `message.from` de un mensaje entrante real llega
como dígitos puros sin `+` — exactamente la forma que produce este normalizador, sin ninguna
transformación adicional de nuestro lado.

Alternativa descartada: el normalizador de
`docs/planes/arquitectura-notificaciones.md:693-697`, que quita el `9` (`549...` → `54...`).
Descartado explícitamente: convierte cada opt-out argentino en un no-op permanente y silencioso,
porque el lado de salida nunca quitó el `9`. Se deja un comentario de advertencia en
`PhoneNumber` citando este archivo y esta línea para que nadie lo reintroduzca "corrigiendo" el
normalizador.

**DEC-02 — Normalizador compartido como clase de soporte estática; SIN mutator en `Contact` ni migración de datos en esta iteración**

Decisión: se crea `App\Notifications\Support\PhoneNumber` con un único método estático
`normalize(string $raw): string`. La usan: (a) `AlertRecipient::toDto()` (reemplaza el método
privado que hoy duplica la misma lógica), (b) `RecordInboundOptOut` (nuevo, escribe
`opt_outs.phone`), y (c) el `OptOut` model vía un mutator de atributo (ver DEC-03). **No** se
agrega un mutator en `Contact::value` ni una migración de backfill de los contactos existentes.

Justificación: los dos puntos donde el formato realmente importa —el envío (ya normaliza en
`toDto()`) y la escritura de un opt-out (normaliza en `RecordInboundOptOut`)— parten ambos del
mismo `Contact::value` crudo y aplican la MISMA función. Persistir la forma normalizada en la
tabla `contacts` no destraba nada que hoy esté bloqueado: es un cambio de shape de datos con
riesgo de tocar filas de producción (auditar cada contacto no-dígitos-only existente) sin un
beneficio funcional inmediato. Es trabajo de calidad de datos, no de esta feature.

Alternativa descartada: mutator + migración de datos ahora. Se descarta por alcance: agrega
riesgo (backfill de producción) a un ticket que ya toca suficiente superficie, sin destrabar
ningún caso de uso adicional. Queda anotado en "Pendientes / fuera de alcance".

**DEC-03 — Guarda de formato a nivel de modelo en `OptOut::phone` (cierra parte de R4)**

Decisión: `OptOut` gana un mutator que normaliza `phone` en cada escritura, sin importar el
llamador (el nuevo webhook, un seeder, `tinker`, un test).

Justificación: el comentario `->comment('E.164 sin +, normalizado')` de la migración es
metadata, no una restricción — SQLite (la DB de tests) ni siquiera la conserva. Sin una guarda
de aplicación, cualquier código que alguna vez escriba en `opt_outs` sin pasar por
`RecordInboundOptOut` puede introducir un formato desalineado de forma silenciosa. El mutator no
reemplaza el test de formato desalineado que pide R4 (ver plan de tests): documenta que, aun con
la guarda puesta, dos cadenas de dígitos *sintácticamente* válidas pero *semánticamente*
distintas (`5491134290838` vs. `541134290838`) siguen sin matchear — eso es un problema de
contrato entre lados, no de formato de columna, y ningún mutator lo puede resolver por sí solo.

Alternativa descartada: un `CHECK` constraint a nivel de base. Descartado: SQLite (tests) y
MySQL (producción) tienen soporte y sintaxis distintos para `CHECK`, y el problema real no es
"¿son dígitos?" sino "¿es la MISMA identidad que el lado de envío?", algo que ninguna constraint
de columna puede validar.

**DEC-04 — `contacts.*.value`: se cierra el agujero de validación tocando 8 archivos, no 11**

Verificado en el código: de los 11 request classes que el ticket menciona, **4** ya usan el
trait `App\Http\Requests\Concerns\ValidatesContactsArray`
(`UpdateMyVetProfileRequest`, `UpdateVetStaffRequest`,
`Members\Client\UpdateClientStaffRequest`, `Clients\UpdateClientRequest`) y **7** duplican las
mismas reglas en línea, sin el trait (`Vets\StoreVetRequest`, `Vets\UpdateVetRequest`,
`Members\AssignVetStaffRequest`, `Members\Client\AssignClientStaffRequest`,
`Members\Client\CreateClientStaffRequest`, `Members\CreateVetStaffRequest`,
`Clients\StoreClientRequest`).

Decisión: agregar un método nuevo y aislado al trait,
`contactValueFormatRule(): \Closure`, que reproduce el mismo contrato E.164 que
`StoreContactRequest`/`UpdateContactRequest` ya aplican en el endpoint individual de contacto,
pero resuelto contra el tipo del MISMO ítem del array (no un campo top-level). Se agrega esa
regla a `contactsRules()` (beneficia a los 4 archivos que ya usan el trait sin tocarlos). En
los 7 duplicadores se agrega `use ValidatesContactsArray;` y se referencia
`$this->contactValueFormatRule()` como cuarto elemento del array `contacts.*.value` que ya
tienen — un cambio de una línea por archivo, sin tocar el resto de sus reglas.

Justificación: es la validación que YA existe para el endpoint `/contacts` individual —no es un
estándar nuevo, es cerrar una inconsistencia entre dos caminos que deberían exigir lo mismo. Sin
esto, `contacts.*.value` (el camino real que alimenta `ContactService::syncContacts()`, o sea el
que usan las altas de Vet/Client/Staff) acepta cualquier string de hasta 200 caracteres —
`"011 15-3429-0838"` pasa hoy sin problema— y ese valor, aunque se envíe con éxito o no, nunca
puede coincidir con el `wa_id` real que reporta Meta cuando esa persona responde `BAJA`. Un
opt-out que no puede matchear un contacto mal guardado es, en la práctica, la mitad de la
feature sin construir.

Alternativa descartada: adoptar `contactsRules()`/`contactsMessages()` completos en los 7
archivos duplicadores (reemplazar todo su método `rules()` por `array_merge(...)`, como ya hacen
los 4 que usan el trait). Se descarta para ESTE ticket: `contactsRules()` también valida
`contacts.*.guid`, regla que hoy esos 7 archivos no declaran. Adoptarla de punta a punta cambia
más comportamiento del estrictamente necesario para cerrar R3, en archivos de módulos ajenos a
notificaciones (Vets, Members, Clients). El cambio de una línea logra el mismo cierre de
validación con superficie mínima; la consolidación total del resto del array queda como mejora
de estilo para otro ticket.

Riesgo asumido: si algún fixture de test de esos 7 módulos usa un `contacts.*.value` con
espacios o guiones para `type: phone|whatsapp`, ese test va a empezar a fallar. Es la señal de
que el agujero de R3 era real, no una regresión — el plan de tests lo contempla explícitamente
(ver "Orden de implementación").

**DEC-05 — El opt-out de email queda fuera de alcance, documentado con su consecuencia**

Decisión: no se toca la irrepresentabilidad de R5 en esta iteración.

Justificación: hacerlo bien requiere un cambio de shape en `opt_outs` (una columna adicional
tipo `identifier` genérico, o permitir `phone` nulo con una columna `email` paralela), porque
hoy `Recipient::$phone` es `null` para `Channel::Email` y `where('phone', null)` se traduce a
`whereNull` sobre una columna `NOT NULL`, que nunca matchea. Ninguno de los dos entregables
confirmados de este ticket (opt-out por WhatsApp, webhook de Twilio) lo requiere.

Consecuencia documentada: si mañana se pide un link de desuscripción en `AlertMail`, la tabla
`opt_outs` actual no lo puede representar sin una migración de columna. `OptOutPolicy` seguirá
corriendo sin efecto para `Channel::Email` (como ya ocurre hoy) — no es una regresión de este
cambio, es el estado preexistente que este ticket no resuelve.

**DEC-06 — `opt_outs` sigue siendo global (sin `vet_id`)**

Decisión: no se agrega `vet_id` a `opt_outs`. Un opt-out por `(phone, channel)` suprime para
**todas** las veterinarias, no solo la que originó el mensaje.

Argumento a favor de global: un opt-out es una decisión de la persona sobre CÓMO se le puede
contactar en un canal, no una decisión comercial ligada a una relación con una veterinaria en
particular — es la misma semántica que "STOP" en cualquier plataforma de mensajería masiva
(incluida la política de Meta sobre WhatsApp Business Platform). Si el mismo número queda dado
de baja en WhatsApp para el Vet A pero el Vet B (usando el mismo `WHATSAPP_PROVIDER`) le sigue
escribiendo, se viola la intención de la persona que pidió la baja — un riesgo legal/reputacional
mayor al que se busca cerrar con este ticket. Además, el propio ticket ya asume esta semántica
como intencional: la baja de Kapso suprime también los envíos por Twilio, cruzando proveedores
sobre el mismo canal.

Argumento a favor de per-vet (considerado y descartado): una veterinaria podría razonablemente
esperar que una baja pedida a través de OTRA veterinaria no le aplique, sobre todo si dos vets no
relacionadas comparten sistema pero no comparten cliente. Requeriría: agregar `vet_id` a
`opt_outs`, un índice único compuesto `(vet_id, phone, channel)`, y — más grave — que
`Recipient` (hoy sin ningún campo de tenant) cargue el `vet_id` de principio a fin a través de
`AlertRecipient::toDto()`, los builders y el pipeline, solo para que `OptOutPolicy::check()`
pueda filtrar por él.

Decisión final: **global**. El caso "dos vets no relacionadas comparten el mismo productor con
el mismo número de WhatsApp" es el caso límite real, pero incluso en ese caso, respetar la baja
para ambas es la lectura más defendible de "la persona pidió que no le escriban por este canal".
Cambiar esto más adelante (agregar scoping por vet) es aditivo — se puede hacer sin romper el
comportamiento actual —, así que no es una decisión que bloquee nada de este ticket.

**DEC-07 — Vocabulario de baja/alta: lista blanca de coincidencia exacta, no de substring**

Decisión: `RecordInboundOptOut` normaliza el cuerpo del mensaje (trim, minúsculas, sin acentos
vía `Str::ascii()`, sin signos de puntuación finales) y compara por **igualdad exacta** contra
dos listas:

- Baja: `baja`, `stop`, `cancelar`, `desuscribir`, `desuscribirme`.
- Alta (revierte una baja existente): `alta`, `start`, `suscribir`, `suscribirme`.

Justificación: en español rioplatense la gente escribe libremente ("dale, bajenme de esto", "ya
no quiero mas mensajes de baja de peso del ganado"). Buscar la palabra `baja` como *substring*
generaría falsos positivos evidentes (el segundo ejemplo). Restringir a una lista de mensajes
completos (tras normalizar) es más conservador — puede haber falsos negativos (alguien que pide
la baja con una frase no reconocida no se da de baja automáticamente) — pero un falso positivo
en un opt-out es mucho más costoso que un falso negativo: dar de baja por error a alguien que no
lo pidió es un fallo de entrega silencioso e indetectable; no reconocer una frase nueva es, en el
peor caso, un mensaje sin respuesta que un soporte humano puede resolver (fuera de alcance de
este ticket, pero no roto por este diseño).

Alternativa descartada: matching por substring/keywords sueltas. Descartado por el riesgo de
falso positivo recién descripto.

**DEC-08 — Se amplía la suscripción de Kapso a `whatsapp.message.received` (R7 verificado)**

Verificación hecha contra `https://docs.kapso.ai/docs/platform/webhooks/event-types` (fuente
viva, no solo el plan que lo mencionaba como trabajo futuro): el evento existe, se llama
literalmente `whatsapp.message.received`, se entrega vía `X-Webhook-Event`, y su payload trae
`message.id`, `message.from` (dígitos sin `+`, tal como predice DEC-01), `message.text.body`
para mensajes de texto, y opcionalmente un envoltorio de batch si el webhook tiene buffering
habilitado.

Decisión: agregarlo a la lista de eventos suscriptos, con `buffer_enabled: false` (ya es el valor
que usa el webhook existente para los cuatro eventos de estado — se mantiene sin cambios, así
que cada `whatsapp.message.received` llega como un evento individual, nunca en batch).

Hay que tocar **tres** lugares (tal como advierte R7), y ninguno alcanza sin re-registrar el
webhook contra Kapso:

1. `KapsoRegisterWebhookCommand::EVENTS` — se agrega el evento a la constante.
2. `KapsoSimulateWebhookCommand` — gana `--event=received` con un payload de mensaje entrante.
3. Un operador **debe correr** `php artisan kapso:register-webhook <url> --update` después de
   este cambio. El código nuevo, sin este paso, entrega cero eventos entrantes — la lista de
   eventos suscriptos vive en la plataforma de Kapso, fijada al momento del registro, no en el
   repo.

**DEC-09 — La rama entre "estado de entrega" y "mensaje entrante" va en el job, antes de `RecordDeliveryStatus` (R8)**

Decisión: `ProcessKapsoWebhookEventJob` (renombrado, ver DEC-10) rama por `event_type` ANTES de
llamar a `RecordDeliveryStatus`:

```php
$outcome = $event->event_type === 'whatsapp.message.received'
    ? $inbound->apply($event)      // RecordInboundOptOut — nuevo
    : $recorder->apply($event);    // RecordDeliveryStatus — sin cambios de contrato
```

Justificación: un mensaje entrante no tiene `message.id` correlacionable a ningún
`AlertRecipient.provider_message_id` (es el id del mensaje que ENVIÓ el cliente, no de uno que
nosotros mandamos). Dejar que `RecordDeliveryStatus::apply()` lo procese sin ramificar antes
haría que, en el mejor caso, tire `UnsupportedWebhookEventException` (si el event_type no está en
su mapa) o, en el peor, que un `message.id` entrante coincida por casualidad con un
`provider_message_id` de un envío nuestro y se aplique un estado sin sentido. Ramificar en el
job (no en el controller, no en `RecordDeliveryStatus`) es el punto correcto: el controller debe
seguir siendo agnóstico de evento (persiste cualquier payload igual), y `RecordDeliveryStatus`
debe seguir siendo agnóstico de "de dónde vino" — solo sabe interpretar estados de entrega.

Servicio nuevo: `App\Notifications\Services\RecordInboundOptOut`.

```php
namespace App\Notifications\Services;

use App\Notifications\Enums\Channel;
use App\Notifications\Models\OptOut;
use App\Notifications\Models\WhatsappWebhookEvent;
use App\Notifications\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Interprets one `whatsapp.message.received` event. Deliberately narrow: opt-out / opt-in
 * keyword detection only. Confirmations and support-conversation forwarding are out of scope
 * (see the ticket) and reuse this same inbound event when they land.
 */
final class RecordInboundOptOut
{
    private const OPT_OUT_KEYWORDS = ['baja', 'stop', 'cancelar', 'desuscribir', 'desuscribirme'];
    private const OPT_IN_KEYWORDS = ['alta', 'start', 'suscribir', 'suscribirme'];

    public function apply(WhatsappWebhookEvent $event): string
    {
        $from = data_get($event->payload, 'message.from');
        $body = data_get($event->payload, 'message.text.body');

        if (! is_string($from) || $from === '' || ! is_string($body)) {
            // Non-text messages (image, sticker, button reply...) have no text.body: nothing
            // to act on, and it is not an error — most inbound traffic will look like this.
            return 'mensaje entrante sin from/body aplicable';
        }

        $phone = PhoneNumber::normalize($from);
        $keyword = self::normalizeKeyword($body);

        return match (true) {
            in_array($keyword, self::OPT_OUT_KEYWORDS, true) => $this->optOut($phone),
            in_array($keyword, self::OPT_IN_KEYWORDS, true) => $this->optIn($phone),
            default => 'mensaje entrante sin palabra clave reconocida',
        };
    }

    private function optOut(string $phone): string
    {
        OptOut::firstOrCreate(['phone' => $phone, 'channel' => Channel::Whatsapp->value]);

        return 'opt-out registrado';
    }

    private function optIn(string $phone): string
    {
        $deleted = OptOut::where('phone', $phone)->where('channel', Channel::Whatsapp->value)->delete();

        return $deleted > 0 ? 'opt-in: baja revertida' : 'opt-in: no había baja previa';
    }

    private static function normalizeKeyword(string $body): string
    {
        return (string) Str::of($body)->trim()->lower()->ascii()->trim(" \t\n\r\0\x0B.,!¡?¿");
    }
}
```

**DEC-10 — Renombrar `ProcessKapsoWebhookEventJob` a `ProcessWhatsappWebhookEventJob`**

Decisión: el job pasa a llamarse `App\Notifications\Jobs\ProcessWhatsappWebhookEventJob`.

Justificación: con este ticket, el mismo job procesa eventos de Kapso (estado + entrante) Y de
Twilio (estado). Nada dentro de su cuerpo es específico de Kapso — ya opera puramente sobre
`WhatsappWebhookEvent` + los dos servicios de interpretación. Mantener el nombre viejo sería
información falsa en el código. Verificado el radio de impacto: el nombre de la clase aparece
en exactamente dos archivos de todo el repo (`ProcessKapsoWebhookEventJob.php` y
`KapsoWebhookController.php`); ningún test lo referencia por nombre de clase (las pruebas
existentes verifican efectos en la base, no `Queue::assertPushed(...)`, gracias a
`QUEUE_CONNECTION=sync` en `phpunit.xml`). Es un rename mecánico y de bajo riesgo.

Alternativa descartada: dejar el nombre y aceptar la inexactitud. Descartado: el ticket
explícitamente pide no generar confusión de "esto es de Kapso" cuando ya no lo es.

**DEC-11 — Firma de Twilio: middleware independiente, usando el `RequestValidator` del SDK**

Decisión: `VerifyTwilioWebhookSignature` es una clase nueva e independiente de
`VerifyKapsoWebhookSignature` (no una generalización de la existente). Usa
`Twilio\Security\RequestValidator` (confirmado presente en
`vendor/twilio/sdk/src/Twilio/Security/RequestValidator.php`, parte de `twilio/sdk: ^8.11`, ya
una dependencia del proyecto).

Justificación: los dos esquemas de firma no comparten NADA de lógica reutilizable más allá de
"comparar un hash con `hash_equals`":

| | Kapso | Twilio |
|---|---|---|
| Algoritmo | HMAC-SHA256 | HMAC-SHA1 |
| Entrada firmada | el **body crudo** (bytes exactos) | la **URL completa** + parámetros POST ordenados por clave |
| Codificación del hash | hex | base64 |
| Header | `X-Webhook-Signature` | `X-Twilio-Signature` |
| Secreto | `KAPSO_WEBHOOK_SECRET` (elegido por nosotros al registrar el webhook) | `TWILIO_AUTH_TOKEN` (la cuenta completa) |

Forzar una abstracción común (interfaz `WebhookSignatureVerifier` con una implementación por
proveedor) formalizaría un contrato de una sola línea (`handle($request, $next)`, que el
middleware de Laravel ya provee de forma nativa) sin eliminar ninguna duplicación real, porque no
hay lógica de firma compartida entre ambos. Es la definición de abstracción prematura para
exactamente dos casos concretos.

Se usa el `RequestValidator` del SDK, no una implementación manual del HMAC-SHA1 + ordenamiento
de parámetros: es exactamente el código que Twilio mantiene y testea para esta firma, y
reimplementarlo a mano es la fuente más común de bugs sutiles en integraciones de este tipo
(orden de claves, normalización de puerto en la URL, etc. — todo lo que `RequestValidator` ya
resuelve, visible en su propio código fuente).

```php
namespace App\Notifications\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

final class VerifyTwilioWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('notifications.twilio.token');

        if ($token === '') {
            abort(500, 'TWILIO_AUTH_TOKEN no configurado.');
        }

        $provided = (string) $request->header('X-Twilio-Signature', '');

        // Twilio signs the full external URL Twilio itself calls (NOT the raw body — a
        // completely different scheme from Kapso's) plus every POST param, sorted by key.
        // Behind a reverse proxy or tunnel, $request->fullUrl() must resolve to exactly the
        // URL configured for this webhook, or the signature never matches (see Riesgos).
        $validator = new RequestValidator($token);

        if ($provided === '' || ! $validator->validate($provided, $request->fullUrl(), $request->request->all())) {
            abort(401, 'Firma de webhook de Twilio inválida.');
        }

        return $next($request);
    }
}
```

**DEC-12 — Idempotencia de Twilio: se reusa `whatsapp_webhook_events`, clave derivada de `MessageSid` + `MessageStatus`**

Decisión: no se crea una tabla nueva. `provider` = `'twilio'` (la columna ya existe sin default,
tal como señala el ticket), `idempotency_key` = `"twilio:{$MessageSid}:{$MessageStatus}"`.

Justificación: Twilio no manda ningún header de idempotencia. La pareja `(MessageSid,
MessageStatus)` identifica una transición de estado específica — si Twilio reintenta la MISMA
notificación (mismo Sid, mismo status) por no haber recibido `2xx` a tiempo, la clave es
idéntica y la unicidad de la columna la deduplica exactamente como ya hace Kapso. Si en algún
momento Twilio reporta genuinamente el mismo status dos veces con información distinta (poco
probable, pero no crítico), aplicar el mismo estado dos veces es un no-op gracias a la guarda de
monotonía ya existente en `RecordDeliveryStatus`.

**DEC-13 — Se extiende `RecordDeliveryStatus`, no se duplica ni se envuelve**

Decisión: `RecordDeliveryStatus::EVENT_STATUS` gana siete entradas nuevas, con el prefijo
`twilio.message.*` (deliberadamente NO relabeladas a `whatsapp.message.*`, para que el
`event_type` de una fila sea auditable sin tener que mirar también la columna `provider`):

```php
private const EVENT_STATUS = [
    'whatsapp.message.sent' => DeliveryStatus::Sent,
    'whatsapp.message.delivered' => DeliveryStatus::Delivered,
    'whatsapp.message.read' => DeliveryStatus::Read,
    'whatsapp.message.failed' => DeliveryStatus::Failed,

    // Twilio's MessageStatus vocabulary, translated to the same DeliveryStatus values.
    // "queued"/"sending" both mean only "the provider accepted it" — the same meaning as Sent.
    'twilio.message.queued' => DeliveryStatus::Sent,
    'twilio.message.sending' => DeliveryStatus::Sent,
    'twilio.message.sent' => DeliveryStatus::Sent,
    'twilio.message.delivered' => DeliveryStatus::Delivered,
    'twilio.message.read' => DeliveryStatus::Read,
    'twilio.message.failed' => DeliveryStatus::Failed,
    'twilio.message.undelivered' => DeliveryStatus::Failed,
];
```

El resto de la clase —`PRECEDENCE`, `apply()`, `applyFailure()`, `applyProgress()`, e incluso
`failureReason()`— **no cambia una línea**. Esto es posible porque la reshape de Twilio pasa
por el controller, no por el servicio: `TwilioWebhookController` arma el `payload` guardado en
`whatsapp_webhook_events` con la MISMA forma anidada que ya usa Kapso
(`message.id`, `message.errors.0.{code,title}`), así que `data_get($event->payload,
'message.errors.0.title')` (el que ya usa `failureReason()`) lee un valor de Twilio sin saberlo.

Justificación: es la lectura correcta de "generalizar el servicio" vs. "capa de traducción en el
controller" que plantea el ticket — ambas cosas, cada una donde corresponde. El servicio se
generaliza en lo que es exactamente su responsabilidad (el vocabulario de eventos → estado). El
controller traduce lo que es exactamente su responsabilidad (la forma del payload entrante de
SU proveedor). Ninguna de las dos cosas duplica la lógica de precedencia monótona ni el
`lockForUpdate` — siguen viviendo en un solo lugar, sin tocar.

Alternativa descartada 1: una clase `TwilioRecordDeliveryStatus` separada, con su propia
precedencia. Descartado: duplicaría exactamente la lógica que el ticket pide no duplicar.

Alternativa descartada 2: relabelar los `event_type` de Twilio a `whatsapp.message.*` (mismo
nombre que Kapso) al persistirlos. Descartado: perdería, sin necesidad, la trazabilidad de qué
proveedor generó cada fila con solo mirar `event_type`; la columna `provider` ya existe pero
usar prefijos distintos hace el dato auto-descriptivo en un `SELECT` rápido durante debugging.

**DEC-14 — `statusCallback` se pasa explícitamente en cada envío, resuelto vía `route()`**

Decisión: `TwilioWhatsappGateway::send()` agrega `'statusCallback' => $this->statusCallbackUrl`
a las opciones del mensaje (cuando está configurado), en vez de depender de que alguien
configure a mano la "Status Callback URL" del Messaging Service en la consola de Twilio.

Justificación: Twilio no llama a nuestro webhook de estados automáticamente — necesita saber
la URL, y hay dos formas de dárselo: configuración fuera de banda en la consola (por Messaging
Service), o el parámetro `statusCallback` en cada request de envío. La segunda queda versionada
en el repo, es explícita, y no depende de que un operador recuerde replicar la configuración de
consola en cada entorno (dev, staging, producción) por separado. Se agrega un cuarto parámetro
opcional al constructor (`?string $statusCallbackUrl = null`) — cuando es `null` (el default,
que usan todos los tests unitarios existentes de este gateway sin cambios), la clave ni se
agrega al array de opciones, así que **ningún test existente de `TwilioWhatsappGatewayTest` se
rompe**.

Config nueva, opcional: `notifications.twilio.status_callback_url` (env
`TWILIO_STATUS_CALLBACK_URL`, default vacío). Si está vacía, `NotificationServiceProvider`
resuelve `route('webhooks.twilio')`. La variable de override existe para poder apuntar a un
túnel local sin tocar `APP_URL` global (que afecta el resto de la app).

Alternativa descartada: depender únicamente de la configuración de consola de Twilio.
Descartado: no queda versionado, es fácil de olvidar al levantar un entorno nuevo, y el propio
ticket señala como principio general "documentar en la guía qué debe hacer un operador para
activar esto" — un paso manual en un dashboard externo es justo lo que este diseño evita.

**DEC-15 — `twilio:simulate-webhook`, en alcance**

Decisión: se agrega, mirando exactamente el mismo patrón de `kapso:simulate-webhook`: firma un
POST `application/x-www-form-urlencoded` contra el endpoint local usando
`RequestValidator::computeSignature()` (el mismo SDK, evita reimplementar el algoritmo de firma
también en la herramienta de diagnóstico) y reporta el efecto real leyendo la base después.

Justificación: sin esto, cada iteración de prueba del circuito de Twilio requiere un envío real
vía Twilio y esperar a que el status realmente cambie en su backend (sujeto a latencia y a
comportamiento no determinístico) — exactamente el problema que `kapso:simulate-webhook` ya
resolvió para Kapso. El costo de construirlo es bajo (reusa el mismo `RequestValidator` y el
mismo patrón de reporte).

## Cambios en BACKEND

### Migraciones

Ninguna. `opt_outs` y `whatsapp_webhook_events` ya tienen shape suficientemente genérico
(`channel` string libre, `provider` sin default) para ambos entregables — confirmado en DEC-05 y
DEC-06 que ningún cambio de schema es necesario para el alcance confirmado.

### Archivos a crear

#### `back/app/Notifications/Support/PhoneNumber.php`

**Propósito:** normalizador de teléfono compartido entre envío y opt-out (DEC-01, DEC-02).

```php
<?php

namespace App\Notifications\Support;

/**
 * Canonical phone identity for the notifications subsystem: digits only, exactly as
 * Meta/WhatsApp reports it — country code plus subscriber number, INCLUDING Argentina's
 * mobile "9" marker.
 *
 * Do NOT strip the leading '9' after Argentina's '54' country code. A previous design draft
 * proposed doing so on the inbound side (docs/planes/arquitectura-notificaciones.md:693-697).
 * Implementing that turns every Argentine opt-out into a silent, permanent no-op: outbound
 * contacts are normalized WITH the '9' (see AlertRecipient::toDto()), and OptOutPolicy
 * compares with an exact SQL '='.
 */
final class PhoneNumber
{
    public static function normalize(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    private function __construct()
    {
        // Static-only helper.
    }
}
```

#### `back/app/Notifications/Services/RecordInboundOptOut.php`

Ver snippet completo en DEC-09.

#### `back/app/Notifications/Http/Middleware/VerifyTwilioWebhookSignature.php`

Ver snippet completo en DEC-11.

#### `back/app/Notifications/Http/Controllers/TwilioWebhookController.php`

**Propósito:** persiste el evento de estado de Twilio con la misma forma de payload que ya
consume `RecordDeliveryStatus`, y despacha el job compartido. Siempre responde `2xx` salvo firma
inválida — un `4xx`/`5xx` acá dispara los reintentos de Twilio y no arregla nada de nuestro lado.

```php
<?php

namespace App\Notifications\Http\Controllers;

use App\Notifications\Jobs\ProcessWhatsappWebhookEventJob;
use App\Notifications\Models\WhatsappWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TwilioWebhookController
{
    public function __invoke(Request $request): Response
    {
        $sid = trim((string) $request->input('MessageSid', ''));
        $status = trim((string) $request->input('MessageStatus', ''));

        if ($sid === '' || $status === '') {
            // Not a status callback shape we recognize. Acknowledge anyway: Twilio does not
            // retry on 2xx, and there is nothing actionable here.
            return response()->noContent();
        }

        $event = WhatsappWebhookEvent::query()->make([
            'provider' => 'twilio',
            'idempotency_key' => "twilio:{$sid}:{$status}",
            'event_type' => "twilio.message.{$status}",
            'provider_message_id' => $sid,
            'payload' => $this->payload($request, $sid, $status),
        ]);

        try {
            $event->save();
        } catch (UniqueConstraintViolationException) {
            return response()->noContent(); // Retried by Twilio, not a new event.
        }

        ProcessWhatsappWebhookEventJob::dispatch($event->id);

        return response()->noContent();
    }

    /**
     * Reshapes Twilio's flat form params into the SAME nested shape Kapso's payload already
     * has (`message.id`, `message.errors.0.{code,title}`), so RecordDeliveryStatus reads it
     * without knowing which provider sent it.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $sid, string $status): array
    {
        $errorCode = $request->input('ErrorCode');
        $errorMessage = $request->input('ErrorMessage');

        $message = ['id' => $sid];

        if ($errorCode !== null || $errorMessage !== null) {
            $message['errors'] = [['code' => $errorCode, 'title' => $errorMessage]];
        }

        return ['message' => $message, 'twilio' => ['message_status' => $status]];
    }
}
```

#### `back/app/Console/Commands/TwilioSimulateWebhookCommand.php`

Mismo patrón que `KapsoSimulateWebhookCommand` (ver DEC-15): firma con
`(new RequestValidator($token))->computeSignature($url, $params)`, postea
`application/x-www-form-urlencoded` con `Http::asForm()`, y reporta `whatsapp_webhook_events` +
`alert_recipients` + eventuales recipients de fallback tras el POST, igual que su equivalente de
Kapso.

```
php artisan twilio:simulate-webhook SM1234567890 --status=delivered
php artisan twilio:simulate-webhook SM1234567890 --status=failed --error-code=63016
```

### Archivos a renombrar

#### `back/app/Notifications/Jobs/ProcessKapsoWebhookEventJob.php` → `back/app/Notifications/Jobs/ProcessWhatsappWebhookEventJob.php`

Clase renombrada `ProcessKapsoWebhookEventJob` → `ProcessWhatsappWebhookEventJob` (DEC-10). Se
le agrega la rama de DEC-09:

**Antes:**
```php
public function handle(RecordDeliveryStatus $recorder): void
{
    $event = WhatsappWebhookEvent::find($this->eventId);

    if ($event === null || $event->processed_at !== null) {
        return;
    }

    try {
        $outcome = $recorder->apply($event);
    } catch (UnsupportedWebhookEventException $e) {
        ...
```

**Después:**
```php
public function handle(RecordDeliveryStatus $recorder, RecordInboundOptOut $inbound): void
{
    $event = WhatsappWebhookEvent::find($this->eventId);

    if ($event === null || $event->processed_at !== null) {
        return;
    }

    try {
        $outcome = $event->event_type === 'whatsapp.message.received'
            ? $inbound->apply($event)
            : $recorder->apply($event);
    } catch (UnsupportedWebhookEventException $e) {
        ...
```

El resto del job (manejo de `processed_at`/`outcome`/`error`, el hook `failed()`) no cambia.

### Archivos a modificar

#### `back/app/Notifications/Models/AlertRecipient.php`

**Cambio:** usar el normalizador compartido en vez del método privado duplicado.

**Antes:**
```php
phone: $this->channel === Channel::Email ? null : self::normalizePhone($contact->value),
...
private static function normalizePhone(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}
```

**Después:**
```php
use App\Notifications\Support\PhoneNumber;
...
phone: $this->channel === Channel::Email ? null : PhoneNumber::normalize($contact->value),
```

Se elimina el método privado `normalizePhone()`.

#### `back/app/Notifications/Models/OptOut.php`

**Cambio:** mutator de normalización (DEC-03).

```php
use App\Notifications\Support\PhoneNumber;
...
protected function setPhoneAttribute(string $value): void
{
    $this->attributes['phone'] = PhoneNumber::normalize($value);
}
```

#### `back/app/Notifications/Services/RecordDeliveryStatus.php`

**Cambio:** extender `EVENT_STATUS` con las siete claves `twilio.message.*` (DEC-13). Sin
ningún otro cambio en la clase.

#### `back/app/Notifications/Http/Controllers/KapsoWebhookController.php`

**Cambio:** actualizar el `use` y la llamada de dispatch al job renombrado.

```php
use App\Notifications\Jobs\ProcessWhatsappWebhookEventJob;
...
ProcessWhatsappWebhookEventJob::dispatch($event->id);
```

Sin ningún otro cambio: el controller ya persiste `whatsapp.message.received` igual que
cualquier otro evento — no necesita saber que ahora existe una rama de inbound.

#### `back/app/Notifications/Gateways/Twilio/TwilioWhatsappGateway.php`

**Cambio:** cuarto parámetro opcional + `statusCallback` condicional (DEC-14).

**Antes:**
```php
public function __construct(
    private readonly Client $twilio,
    private readonly string $from,
    private readonly array $templates = [],
) {}

public function send(OutboundMessage $message): DeliveryResult
{
    $to = 'whatsapp:+' . $message->recipient->phone;

    try {
        $sent = match (true) {
            $message->content instanceof TemplateContent => $this->twilio->messages->create($to, [
                'from' => $this->from,
                'contentSid' => $this->resolveContentSid($message->content->type),
                'contentVariables' => json_encode($message->content->variables),
            ]),
            $message->content instanceof TextContent => $this->twilio->messages->create($to, [
                'from' => $this->from,
                'body' => $message->content->body,
            ]),
```

**Después:**
```php
public function __construct(
    private readonly Client $twilio,
    private readonly string $from,
    private readonly array $templates = [],
    private readonly ?string $statusCallbackUrl = null,
) {}

public function send(OutboundMessage $message): DeliveryResult
{
    $to = 'whatsapp:+' . $message->recipient->phone;
    $options = ['from' => $this->from];

    if ($this->statusCallbackUrl !== null) {
        $options['statusCallback'] = $this->statusCallbackUrl;
    }

    try {
        $sent = match (true) {
            $message->content instanceof TemplateContent => $this->twilio->messages->create($to, $options + [
                'contentSid' => $this->resolveContentSid($message->content->type),
                'contentVariables' => json_encode($message->content->variables),
            ]),
            $message->content instanceof TextContent => $this->twilio->messages->create($to, $options + [
                'body' => $message->content->body,
            ]),
```

Con `$statusCallbackUrl === null` (el default), `$options` es exactamente `['from' => ...]`, así
que las cinco pruebas existentes de `TwilioWhatsappGatewayTest` (que no pasan el cuarto
argumento) siguen pasando sin modificar una línea.

#### `back/app/Notifications/NotificationServiceProvider.php`

**Cambio:** el binding de `TwilioWhatsappGateway` resuelve el `statusCallbackUrl`.

```php
return new TwilioWhatsappGateway(
    new Client($sid, $token),
    $messagingService,
    $config['templates'] ?? [],
    trim((string) ($config['status_callback_url'] ?? '')) ?: route('webhooks.twilio'),
);
```

#### `back/config/notifications.php`

**Cambio:** una clave nueva y opcional en `twilio`.

```php
'twilio' => [
    'sid' => env('TWILIO_ACCOUNT_SID'),
    'token' => env('TWILIO_AUTH_TOKEN'),
    'messaging_service' => env('TWILIO_TEMPLATE_MESSAGE_SERVICE'),
    // Optional override for local tunnels: when empty, resolved from route('webhooks.twilio').
    'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
    'templates' => [ ... sin cambios ... ],
],
```

#### `back/app/Console/Commands/KapsoRegisterWebhookCommand.php`

**Cambio:** agregar el evento entrante a la constante (DEC-08).

```php
private const EVENTS = [
    'whatsapp.message.received',
    'whatsapp.message.sent',
    'whatsapp.message.delivered',
    'whatsapp.message.read',
    'whatsapp.message.failed',
];
```

El comentario de la constante ("Delivery-status events only: inbound messages are out of scope
for the alerts pipeline") se actualiza para reflejar que el opt-out entrante ya está en alcance.

#### `back/app/Console/Commands/KapsoSimulateWebhookCommand.php`

**Cambio:** nueva opción `--event=received` con su propio shape de payload.

```php
private const EVENTS = ['sent', 'delivered', 'read', 'failed', 'received'];
```

```php
{--from= : Teléfono remitente, solo con --event=received}
{--text=BAJA : Cuerpo del mensaje entrante, solo con --event=received}
```

```php
private function payload(string $eventType, string $wamid, string $event): array
{
    if ($event === 'received') {
        return [
            'type' => $eventType,
            'message' => [
                'id' => $wamid,
                'from' => (string) $this->option('from'),
                'text' => ['body' => (string) $this->option('text')],
            ],
        ];
    }

    // ... payload de estado existente, sin cambios ...
}
```

`reportEffect()` gana una línea que consulta `OptOut::where('phone', PhoneNumber::normalize(...))`
cuando `--event=received`, para poder verificar el efecto sin abrir `tinker`.

#### `back/app/Http/Requests/Concerns/ValidatesContactsArray.php`

Ver snippet completo en DEC-04 (`contactValueFormatRule()` + su uso en `contactsRules()`).

#### Siete request classes (una línea cada una, DEC-04)

`back/app/Http/Requests/Vets/StoreVetRequest.php`,
`back/app/Http/Requests/Vets/UpdateVetRequest.php`,
`back/app/Http/Requests/Members/AssignVetStaffRequest.php`,
`back/app/Http/Requests/Members/Client/AssignClientStaffRequest.php`,
`back/app/Http/Requests/Members/Client/CreateClientStaffRequest.php`,
`back/app/Http/Requests/Members/CreateVetStaffRequest.php`,
`back/app/Http/Requests/Clients/StoreClientRequest.php`.

En cada uno: agregar `use App\Http\Requests\Concerns\ValidatesContactsArray;` +
`use ValidatesContactsArray;` dentro de la clase, y cambiar:

```php
'contacts.*.value' => ['required', 'string', 'max:200'],
```
por
```php
'contacts.*.value' => ['required', 'string', 'max:200', $this->contactValueFormatRule()],
```

#### `back/bootstrap/app.php`

**Cambio:** alias del middleware nuevo.

```php
$middleware->alias([
    'vet.tenant' => \App\Http\Middleware\EnsureUserBelongsToVet::class,
    'kapso.signature' => \App\Notifications\Http\Middleware\VerifyKapsoWebhookSignature::class,
    'twilio.signature' => \App\Notifications\Http\Middleware\VerifyTwilioWebhookSignature::class,
]);
```

#### `back/routes/api/notifications-webhooks.php`

```php
Route::post('v1/webhooks/twilio', TwilioWebhookController::class)
    ->middleware('twilio.signature')
    ->name('webhooks.twilio');
```

#### `back/.env.example`

Sin cambios obligatorios: `TWILIO_ACCOUNT_SID`/`TWILIO_AUTH_TOKEN`/
`TWILIO_TEMPLATE_MESSAGE_SERVICE` ya existen (obligatorias si `WHATSAPP_PROVIDER=twilio`, sin
relación con este ticket). Se agrega, comentada, la única variable nueva:

```env
# TWILIO_STATUS_CALLBACK_URL=https://xxxx.trycloudflare.com/api/v1/webhooks/twilio
```

### Rutas API

| Método | Path | Controller | Middleware | Auth |
| --- | --- | --- | --- | --- |
| POST | `v1/webhooks/twilio` | `TwilioWebhookController` | `twilio.signature` | Ninguna — callback de proveedor, firma HMAC es la autenticación (igual que `v1/webhooks/kapso`) |

### Permisos Spatie

No aplica. Es un callback de proveedor sin usuario autenticado, exactamente igual que
`v1/webhooks/kapso`.

### Contrato de los endpoints

**`POST /api/v1/webhooks/twilio`**

Request: `application/x-www-form-urlencoded`, campos estándar de Twilio Status Callback
(`MessageSid`, `MessageStatus`, `To`, `From`, opcionalmente `ErrorCode`/`ErrorMessage`).
Header `X-Twilio-Signature` obligatorio.

Response 2xx: `204 No Content` en todos los casos aceptados (nuevo evento, duplicado, o payload
sin `MessageSid`/`MessageStatus` reconocible).

Errores:
- `401` — firma inválida o ausente.
- `500` — `TWILIO_AUTH_TOKEN` no configurado (falla cerrado, igual que el equivalente de Kapso).

### Tests a generar

**Unit**

- `back/tests/Unit/Notifications/Support/PhoneNumberTest.php` (nuevo) — normaliza formatos con
  espacios/guiones/`+`; **no** quita el `9` de un móvil argentino (pin de regresión de R1);
  idempotente sobre una entrada ya normalizada; cadena sin dígitos → `''`.
- `back/tests/Unit/Notifications/OptOutModelTest.php` (nuevo) — el mutator de `phone` normaliza
  al crear; idempotente si ya viene normalizado.
- `back/tests/Unit/Notifications/OptOutPolicyTest.php` (modificar, agregar):
  - Un `OptOut` creado con formato suelto (`+54 9 11 3429-0838`) sí suprime un recipient cuyo
    contacto normaliza al mismo valor.
  - **Test de formato desalineado exigido por R4**: un `OptOut` persistido directamente con la
    forma "9 quitado" (`541134290838` — la que produciría el normalizador descartado en DEC-01)
    **no** suprime un recipient cuyo teléfono normalizado real es `5491134290838`. El comentario
    del test debe explicitar que esto es exactamente lo que rompería si alguien reintroduce el
    normalizador de `docs/planes/arquitectura-notificaciones.md`.
- `back/tests/Unit/Notifications/RecordInboundOptOutTest.php` (nuevo) — cada palabra clave de
  baja y de alta (case/acento-insensible, con puntuación al final); alta sin baja previa
  (no-op sin excepción); texto libre no reconocido (no crea fila); mensaje sin
  `message.text.body` (imagen/sticker) → no-op; repetir el mismo mensaje de baja dos veces →
  una sola fila en `opt_outs` (`firstOrCreate`).
- `back/tests/Unit/Notifications/RecordDeliveryStatusTest.php` (modificar, agregar) —
  `twilio.message.delivered` sobre un recipient `Sent` avanza a `Delivered` igual que su
  equivalente Kapso; `twilio.message.failed` con el payload reshapeado por el controller lee el
  `code`/`title` igual que `failureReason()` ya hace para Kapso.
- `back/tests/Unit/Notifications/TwilioWhatsappGatewayTest.php` (modificar, agregar un test) —
  con el cuarto argumento seteado, `create()` recibe `statusCallback` en las opciones; los cinco
  tests existentes no requieren ningún cambio (documentar esto en el PR).
- `back/tests/Unit/Notifications/Http/Middleware/VerifyTwilioWebhookSignatureTest.php` (nuevo) —
  firma válida (calculada con `RequestValidator::computeSignature()`) pasa; firma inválida → 401;
  token vacío → 500; firma calculada contra una URL distinta (ej. con/sin trailing slash) → 401,
  documentando la sensibilidad a la URL exacta.

**Feature**

- `back/tests/Feature/TwilioWebhookTest.php` (nuevo) — POST firmado válido → `204` y fila en
  `whatsapp_webhook_events` (`provider=twilio`, `event_type=twilio.message.delivered`); mismo
  `MessageSid`+`MessageStatus` dos veces → una sola fila; `MessageStatus=failed` con
  `ErrorCode`/`ErrorMessage` → recipient `Failed` + recipient de fallback en email creado
  end-to-end; sin firma o firma incorrecta → `401`; progresión completa
  `queued → sent → delivered → read` respeta precedencia.
- `back/tests/Feature/KapsoWebhookTest.php` (modificar, agregar) — `type:
  whatsapp.message.received` con `message.text.body: "BAJA"` y `message.from` → crea una fila en
  `opt_outs`; reenviar el mismo payload con el mismo `X-Idempotency-Key` no duplica la fila.

Recordar las convenciones de testing del proyecto: SQLite in-memory, `RefreshDatabase`,
`WithoutModelEvents` en seeders y `guid` explícito en factories.

## Cambios en FRONTEND

Sin cambios. Ningún endpoint nuevo consumible desde el frontend — ambos webhooks son callbacks
de proveedor sin UI asociada.

## Orden de implementación

Los pasos 1-3 son refactors mecánicos e independientes entre sí y del resto del ticket — pueden
mergearse en PRs separados si conviene acotar el diff:

1. **`PhoneNumber` + `AlertRecipient::toDto()`**. Extraer el normalizador, actualizar el único
   call site. Suite verde (cero cambio de comportamiento).
2. **Mutator en `OptOut::phone`**. Suite verde.
3. **Refactor de validación de contactos** (DEC-04): trait + 7 archivos de una línea. Correr
   toda la suite de Vets/Members/Clients — si algún fixture usaba un valor no-E.164 para
   `phone`/`whatsapp`, este es el punto en que aparece y hay que corregir el fixture (es la
   confirmación de que R3 era real).
4. **Rename de `ProcessKapsoWebhookEventJob` → `ProcessWhatsappWebhookEventJob`**, sin la rama de
   inbound todavía (solo el rename + el `use`/dispatch actualizado en `KapsoWebhookController`).
   Suite verde.

   **Este paso NO es seguro de deployar sin drenar la cola.** El rename invalida los payloads
   serializados de los jobs pendientes en la tabla `jobs` — ver el riesgo correspondiente en
   "Riesgos y consideraciones". La suite verde no lo detecta porque `phpunit.xml` fuerza
   `QUEUE_CONNECTION=sync`. Si el deploy no puede drenar la cola, aplicar el shim de una release
   descripto en ese riesgo.
5. **Opt-out entrante de Kapso**: `RecordInboundOptOut`, la rama en el job, `--event=received` en
   `KapsoSimulateWebhookCommand`, el evento nuevo en `KapsoRegisterWebhookCommand::EVENTS`. Tests
   unitarios y de feature de este bloque.
6. **Webhook de Twilio**: `VerifyTwilioWebhookSignature`, `TwilioWebhookController`, ruta,
   middleware alias, extensión de `RecordDeliveryStatus::EVENT_STATUS`, `statusCallback` en
   `TwilioWhatsappGateway` + binding en el service provider. Tests de este bloque.
7. **`twilio:simulate-webhook`**. Independiente de todo lo demás salvo la ruta del paso 6.
8. Verificación manual end-to-end (ver checklist en "Activación").

## Activación en un entorno real (lo que un operador debe hacer)

**Para el opt-out por Kapso:**

1. Desplegar el código.
2. `php artisan kapso:register-webhook <url-publica> --update --dry-run` para revisar el payload
   (debe listar los cinco eventos, incluido `whatsapp.message.received`).
3. `php artisan kapso:register-webhook <url-publica> --update` (sin `--dry-run`). **Sin este
   paso, cero mensajes entrantes llegan** — la suscripción vive en la plataforma de Kapso, no en
   el repo.
4. Verificar con `php artisan kapso:simulate-webhook <wamid> --event=received --from=<telefono> --text=BAJA`
   contra el entorno correspondiente, y confirmar la fila en `opt_outs`.

No hace falta ninguna variable de entorno nueva para este bloque.

**Para el webhook de estados de Twilio:**

1. Confirmar que `WHATSAPP_PROVIDER=twilio`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`,
   `TWILIO_TEMPLATE_MESSAGE_SERVICE` estén seteados (ya requeridos hoy para poder enviar).
2. Asegurar que `APP_URL` (o `TWILIO_STATUS_CALLBACK_URL` si se prefiere no tocar `APP_URL`)
   resuelva a una URL HTTPS pública que Twilio pueda alcanzar. En local, esto requiere el mismo
   tipo de túnel (`cloudflared`/`ngrok`) que ya documenta la guía para Kapso.
3. `php artisan config:clear` tras cualquier cambio de `.env`.
4. `php artisan route:list --path=webhooks` — deben aparecer `webhooks.kapso` y `webhooks.twilio`.
5. No hace falta ningún registro fuera de banda en la consola de Twilio: `statusCallback` viaja
   en cada envío (DEC-14). Si en el pasado se configuró una "Status Callback URL" a mano en la
   consola del Messaging Service, puede quedar como está (Twilio usa el `statusCallback`
   explícito del request cuando está presente) o limpiarse para evitar confusión operativa.
6. Si hay un `queue:work`/Horizon corriendo como daemon de larga duración, reiniciarlo
   (`php artisan queue:restart`) después del deploy — el job renombrado debe cargarse con el
   nuevo nombre de clase.
7. Verificar con `php artisan twilio:simulate-webhook <MessageSid> --status=delivered` contra el
   entorno correspondiente.

## Actualización de `docs/guias/notificaciones-setup.md`

Secciones a tocar, por número:

- **Sección 1** (diagrama de flujo): agregar la rama paralela
  `POST /api/v1/webhooks/twilio` junto a la de Kapso, y una nota de que el mensaje entrante de
  Kapso (`whatsapp.message.received`) alimenta un camino distinto (`RecordInboundOptOut`), no
  `RecordDeliveryStatus`.
- **Sección 7.5** ("Registrar el webhook"): actualizar "se suscribe exactamente a estos cuatro
  eventos... los mensajes entrantes están fuera de alcance" — ahora son cinco, y el opt-out
  entrante SÍ está en alcance. Agregar el paso de simulación de `--event=received`.
- **Sección 7.6**: agregar `whatsapp.message.received` a la tabla de valores de `--event=` de
  `kapso:simulate-webhook`, con sus opciones `--from=`/`--text=`.
- **Sección 8** (Twilio): agregar una subsección **8.3 — Webhook de estados** (registro
  automático vía `statusCallback`, sin paso manual de consola) y **8.4 — Simular el circuito**
  (`twilio:simulate-webhook`, mismo patrón que 7.6). Renumerar la sección de limitaciones
  conocidas (hoy 8.3) a **8.5**, y reescribirla: ya NO es cierto que "no hay webhook de Twilio
  implementado" ni que "no hay manejo de opt-out entrante" — ambos puntos se resuelven con este
  ticket. Mantener únicamente lo que sigue siendo cierto (`TWILIO_TEMPLATE_PROGRAM_TASK_DUE`
  pendiente de aprobación comercial, sin comando de envío de prueba propio de Twilio, sin timeout
  configurable).
- **Sección 9** (verificación end-to-end): agregar `php artisan route:list --path=webhooks/twilio`
  y un paso de `twilio:simulate-webhook` al checklist.
- **Sección 10** (referencia de comandos): agregar filas para `twilio:simulate-webhook` y
  actualizar la descripción de `kapso:register-webhook`/`kapso:simulate-webhook` (cinco eventos).
- **Sección 11** (troubleshooting): agregar una entrada "El webhook de Twilio responde 401" (la
  URL de la consola/`APP_URL` no coincide byte a byte con la que ve Laravel) y "Las bajas no
  llegan" (recordar `--update` de `kapso:register-webhook` tras este cambio).
- **Sección 12** (anexo de tablas y archivos): actualizar la fila de `whatsapp_webhook_events`
  (mencionar el prefijo `twilio.message.*`), agregar `RecordInboundOptOut`,
  `TwilioWebhookController`, `VerifyTwilioWebhookSignature` a la tabla de archivos, y renombrar
  la referencia a `ProcessKapsoWebhookEventJob`.
- **"Deuda conocida y abierta"**: quitar o reescribir la mención a
  `TKT-006-twilio-sin-webhook-ni-opt-out.md` como deuda abierta — pasa a estar resuelta; dejar
  únicamente la mención a TKT-007 (doc de arquitectura desactualizado) y agregar, si corresponde,
  una nota breve sobre lo que queda en "Pendientes / fuera de alcance" de este plan.

## Riesgos y consideraciones

- **`$request->fullUrl()` debe coincidir exactamente con la URL que Twilio ve.** Detrás de un
  proxy/túnel, un mismatch de esquema (`http` vs `https`) o de puerto invalida la firma para el
  100% de los eventos, no solo algunos — es el mismo tipo de fragilidad que ya documenta el
  ticket para Kapso (re-serialización del body), pero en Twilio la superficie de error es la URL
  completa, no solo el body. El test de firma-contra-URL-distinta (ver plan de tests) existe para
  atrapar esto en CI antes de que aparezca como un 401 constante en producción.
- **DEC-04 puede romper fixtures existentes** en los módulos de Vets/Members/Clients si algún
  test crea contactos `phone`/`whatsapp` con formato no-E.164. Es una señal esperada, no una
  regresión — corregir el fixture, no relajar la regla.
- **DEC-06 (opt-out global) tiene un caso límite documentado**: dos veterinarias no
  relacionadas que comparten el mismo número de un mismo productor verían ambas suprimido el
  envío si una de las dos recibe la baja. Se acepta como la lectura más defendible de la
  intención de la persona (ver DEC-06); revertible más adelante sin romper lo existente si el
  negocio decide que necesita scoping por vet.
- **DEC-05 (opt-out de email fuera de alcance) dejará sin resolver** cualquier pedido futuro de
  desuscripción por email hasta que se rediseñe el shape de `opt_outs`.
- **El rename de DEC-10 rompe los jobs YA encolados al momento del deploy.** La justificación de
  DEC-10 auditó las referencias estáticas (dos archivos, ningún test), pero el nombre de la clase
  también vive en **estado serializado en runtime**. Con `QUEUE_CONNECTION=database` (el valor de
  `.env.example`), cada job pendiente en la tabla `jobs` lleva el FQCN en su payload
  (`data.commandName` y el blob de `serialize()`). Al deployar el rename, todo
  `ProcessKapsoWebhookEventJob` que estuviera en la cola falla al deserializar, cae en
  `failed_jobs` con un class-not-found, y su fila de `whatsapp_webhook_events` queda con
  `processed_at = null` para siempre: esos estados de entrega nunca se aplican.

  Impacto acotado (se pierden actualizaciones de estado, no alertas) y visible en `failed_jobs`,
  pero es una condición de orden de deploy, no un detalle. Mitigación, en orden de preferencia:

  1. Drenar la cola antes de deployar el rename (`php artisan queue:work --stop-when-empty` y
     confirmar `jobs` vacía), que es trivial porque estos jobs son de segundos.
  2. Si no se puede drenar, dejar por UNA release un shim
     `final class ProcessKapsoWebhookEventJob extends ProcessWhatsappWebhookEventJob {}` y
     borrarlo en la siguiente.
  3. Aceptar las entradas en `failed_jobs` y re-procesarlas a mano con `queue:retry` **después**
     de restaurar temporalmente el nombre viejo — la peor opción, listada para descartarla.

  Con `QUEUE_CONNECTION=sync` no hay estado serializado y el riesgo no existe, así que en
  desarrollo local esto no se va a manifestar nunca. Eso es precisamente lo que lo hace peligroso.

- **El vocabulario de baja/alta (DEC-07) es una lista fija.** Si el negocio identifica frases
  adicionales de uso frecuente ("no me interesa", "sacame de la lista"), hay que agregarlas a
  las constantes — no hay aprendizaje automático ni fuzzy-matching por diseño (ver
  justificación de falso-positivo-vs-falso-negativo en DEC-07).
- **Reintentos de Twilio y de Kapso corren sobre el mismo `ProcessWhatsappWebhookEventJob`.** Un
  bug en la interpretación de un proveedor (ej. un `MessageStatus` de Twilio no mapeado) queda
  contenido por el mismo mecanismo ya probado para Kapso (`UnsupportedWebhookEventException` →
  cierre definitivo con el error guardado, sin reintento infinito).

## Pendientes / fuera de alcance

- Mutator en `Contact::value` + migración de backfill de los contactos ya existentes con
  formato no normalizado (DEC-02).
- Opt-out para `Channel::Email` — requiere cambiar el shape de `opt_outs` (DEC-05).
- Scoping de `opt_outs` por `vet_id` (DEC-06) — aditivo si se necesita más adelante.
- Consolidar los 7 request classes duplicadores sobre el resto de `contactsRules()`/
  `contactsMessages()` (no solo la regla de formato) — mejora de estilo, no de comportamiento.
- Ciclo de confirmación (`require_confirmation`/`confirmed_at`) — explícitamente fuera de
  alcance por el ticket; sigue sin resolverse.
- `WhatsappProviderResolver` (ruteo de proveedor por país) — explícitamente fuera de alcance.
- Reenvío de `whatsapp.conversation.*` a soporte — explícitamente fuera de alcance.
- Deprecar Twilio como proveedor — el ticket ya resolvió que no se deprecaba en esta iteración.
