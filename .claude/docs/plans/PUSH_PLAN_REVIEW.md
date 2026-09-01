# Revisión del plan de Web Push — respuesta del equipo mobile

**Para:** equipo de la API / nube
**De:** equipo SAV Mobile (PWA)
**Sobre:** `web-push-notifications-plan.md`
**Fecha:** 2026-08-27

---

## Resumen

El plan está bien y el diagnóstico de fondo es correcto: los dos endpoints de suscripción sin la
segunda mitad (`Channel::Push` cableado a un gateway) no producen ninguna notificación. Adelante con
las dos mitades.

Tres decisiones del plan resuelven cosas que nuestra spec ni había planteado y las tomamos tal cual:
**DEC-04** (`endpoint_hash` en vez de indexar un TEXT), **DEC-06** (reasignación de `endpoint` en
dispositivo compartido) y **DEC-07** (404 que no distingue "no existe" de "es de otro usuario").

Lo que sigue está ordenado por lo que hay que hacer, no por importancia conceptual:

| | Qué | Quién |
|---|---|---|
| **A** | 3 cambios en el payload de push — sin esto las notificaciones llegan rotas | Nube |
| **B** | 2 puntos que marcaron "a confirmar" y ya están confirmados — no requieren cambio | — |
| **C** | 2 decisiones a tomar juntos | Ambos |
| **D** | 2 detalles menores | Nube |
| **E** | 1 circuito abierto de los dos lados | Ambos |
| **F** | 1 cambio nuestro, ya identificado | Mobile |

---

## A · Cambios en el payload de push (bloqueantes)

El service worker (`public/service-worker.js`) ya está escrito y en producción. Estos tres campos
los lee y hoy `PushContent` no los produce.

### A1 · `PushContent` necesita un `tag`

**Qué pasa hoy.** El SW hace `tag: payload.tag` y `renotify: Boolean(payload.tag)`. El `tag` es lo
que colapsa reenvíos de la **misma** alerta en una sola notificación.

**Sin esto.** Cada reintento del job, o cada re-despacho de la misma alerta, apila una notificación
nueva en el teléfono. Un usuario con una alerta que reintentó 3 veces ve 3 notificaciones idénticas.

**Qué hacer.** Agregar `tag` a `PushContent` y llenarlo en los 4 builders con
**`"alert-{guid}"`** — el guid de la alerta, no un id numérico.

### A2 · `PushContent.data` necesita `requires_confirmation`

**Qué pasa hoy.** El SW hace `requireInteraction: Boolean(data.requires_confirmation)`. Eso es lo
que deja la notificación fija en pantalla hasta que el usuario la toca, en vez de que se descarte
sola a los pocos segundos.

**Sin esto.** Una alerta que pide confirmación explícita se comporta igual que cualquier otra y
desaparece sin que nadie la vea. Es justamente la alerta que menos se puede perder.

**Qué hacer.** En los builders, mapear `alerts.require_confirmation` a
`data: ['requires_confirmation' => true]`. Nombre exacto, en snake_case y en plural (`requires`, no
`require`) — es el que lee el SW.

### A3 · El `url` no puede ser absoluto al dominio

**Qué pasa hoy.** La app **no vive en la raíz del dominio**: se sirve desde
`https://sav.com.ar/mobile`. El SW usa `payload.url` tal cual, sin prefijar nada:

```js
const url = payload.url || FALLBACK_NOTIFICATION.url;
```

**Sin esto.** Un `url` de `/programas/{guid}` manda al usuario a
`https://sav.com.ar/programas/{guid}`, que es un 404 fuera de la app. Y como los builders del plan
no setean `url` en absoluto (`new PushContent(title:, body:)`), hoy **toda** notificación caería al
destino por defecto: el home.

**Qué hacer.** Mandar el path **relativo a la raíz de la app, sin barra inicial**:

```
"url": "programas/9f1c8b2a-...."
```

Nosotros lo resolvemos contra el montaje real con `appUrl()`. Preferimos esto a que ustedes
hardcodeen `/mobile/` porque el prefijo es nuestro y puede cambiar sin avisarles.

> ⚠️ **Nunca un id numérico.** El ejemplo `/programs/45` que circula en documentación vieja apunta
> al id local de un circuito que ya no existe. Los programas viven en `programas/{guid}`.

### Contrato final del payload de push

Este es el JSON exacto que espera el service worker. `title` y `body` son los únicos obligatorios;
el resto tiene defaults, pero sin ellos se pierde la función que describe cada uno.

```json
{
  "title": "Brucelosis — La Esperanza",
  "body": "Mañana: revacunar terneras de 3 a 8 meses",
  "tag": "alert-9f1c8b2a-...",
  "url": "programas/d749d12f-...",
  "data": {
    "requires_confirmation": true,
    "alert_guid": "9f1c8b2a-...",
    "program_guid": "d749d12f-..."
  }
}
```

| Campo | Efecto en el dispositivo |
|---|---|
| `title` | Título de la notificación. Default: `SAV Mobile`. |
| `body` | Cuerpo. Default: `Tenés una alerta pendiente.` **Truncar a ~4 KB de payload total** (ver D2). |
| `tag` | Colapsa reenvíos de la misma alerta. Usar `alert-{guid}`. |
| `url` | Destino del click, **relativo a la raíz de la app, sin barra inicial**. Default: el home. |
| `data.requires_confirmation` | `true` deja la notificación fija hasta que la toquen. |
| `data.*` | Todo lo demás viaja intacto al click. Manden guids, no ids numéricos. |

---

## B · Puntos que marcaron "a confirmar" y ya están confirmados

### B1 · DEC-01 no es un desvío del contrato — no hay nada que confirmar

Lo marcaron como *"el único punto donde me aparto del contrato cerrado"* y lo pusieron como
bloqueante de merge. **No lo es: su decisión coincide exactamente con lo que ya hacemos.**

Nuestro `APP_API_ENDPOINT` **ya termina en `/api/v1`**, y `PushApiService` llama a
`/push/subscriptions` sin prefijo. La URL que sale del dispositivo es, hoy, en producción:

```
https://app.save.com.ar/api/v1/push/subscriptions
```

El `/api/push/subscriptions` que leyeron en nuestro contrato era **un bug nuestro**, corregido el
2026-08-21 y cubierto por un test que assertea el request que sale
(`ApiRequestHeadersTest::test_los_endpoints_no_repiten_el_prefijo_api`). La documentación que les
llegó estaba desactualizada.

**Cero cambios de nuestro lado. Mergeen tranquilos.**

### B2 · El shape del request coincide 1:1

`StorePushSubscriptionRequest` acepta exactamente lo que manda `PushSubscription::toCloudPayload()`:
`uuid`, `endpoint`, `keys.p256dh`, `keys.auth`, `content_encoding`, `device_label`, `updated_at`.

- **`user_id` como `prohibited` está perfecto.** Nunca lo mandamos y no queremos poder mandarlo.
- **Ignoren el `mobile_id`** que aparece en nuestro `API_EXTERNA.md`: es documentación vieja, no se
  envía.
- **DEC-07 (404 en el DELETE) es exactamente lo que esperamos.** Nuestro código trata el 404 como
  éxito y ni siquiera parsea el body: `if ($response->status() !== 404) { ... }`.

---

## C · Decisiones a tomar juntos

### C1 · Las llaves VAPID: cada equipo está esperando al otro

Su `.env.example` dice *"Recibidas UNA VEZ del equipo mobile — jamás generar un par nuevo acá"*.
Nuestra lista de pendientes dice *"que la nube nos pase la llave pública"*. **Nadie las generó.**

**Propuesta: las genera la nube.** Dos razones:

1. La nube es la que **firma** los envíos. La llave privada no debería salir nunca del despachador,
   y menos viajar por mail o Slack entre dos equipos.
2. Nosotros solo necesitamos la **pública**, que va en `APP_VAPID_PUBLIC_KEY` y se expone al browser
   igual. No es secreta.

El par que tenemos hoy en nuestro `.env` es **de desarrollo** (`APP_PUSH_DISPATCHER=local`, para
probar sin la API externa). No sirve para producción y no es el que hay que usar.

**Lo que necesitamos de ustedes:** la llave pública de cada ambiente (staging y producción, si son
distintas) y aviso explícito si alguna vez las rotan.

**Por qué importa tanto:** firmar con un par distinto al que usó el navegador al suscribirse falla
con `403 VapidPkHashMismatch` de forma permanente, por suscripción, sin arreglo del lado servidor.
Su DEC-11 lo entendió bien y el `Log::critical` es la decisión correcta. Coincidimos en que la única
mitigación real es de proceso: rotar = purgar `push_subscriptions` + re-suscribir cada dispositivo a
mano.

### C2 · DEC-08 — el problema no es el volumen, es que va a fallar siempre

Ustedes lo plantearon como *"confirmar con producto si push va en TODAS las alertas"* y como riesgo
de duplicación de volumen (2N filas). **El costo real es otro y es peor.**

Su `toPushDto()` lanza `RecipientContactNotFoundException` cuando el `UserProfile` no tiene ninguna
suscripción, lo que marca el `AlertRecipient` como `Failed` y loguea un warning.

Ahora bien: los destinatarios de una alerta se resuelven por **los `roles` del protocolo** — el
encargado del campo, el dueño. Esa gente recibe WhatsApp; **la PWA es una app para veterinarios y la
mayoría de ellos nunca la va a instalar.**

Consecuencia: cada alerta generaría N `AlertRecipient` de canal Push que fallan, en cada despacho,
para siempre. Las métricas de entrega quedan envenenadas y el `Log::critical` de DEC-11 se pierde
entre miles de warnings normales.

**Propuesta concreta, en `AlertRecipientFactory::createForManagers()`:** agregar el canal Push
**solo si ese perfil tiene una suscripción activa**.

```php
foreach ($channels as $channel) {
    if ($channel === Channel::Push && ! $this->hasActiveSubscription($manager)) {
        continue; // no tiene la app: el canal no aplica, no es un fallo de entrega
    }
    // ...
}
```

"No tiene la app instalada" no es un fallo de entrega, es un canal que no corresponde. Con eso, DEC-08
deja de necesitar decisión de producto: push va en todas las alertas, pero solo para quien puede
recibirlas.

---

## D · Detalles menores

### D1 · El match por URI puede dejar suscripciones muertas para siempre

En `WebPushGateway`:

```php
$subscription = $subscriptions->firstWhere('endpoint', (string) $report->getRequest()->getUri());
```

Si ese match falla, `$subscription` queda `null` y la rama de 404/410 **no borra la fila**
(`if ($report->isSubscriptionExpired() && $subscription !== null)`). La suscripción muerta sobrevive
y vuelve a fallar en cada alerta futura, que es justo lo que esa rama quiere evitar.

Sugerencia: indexar la colección por endpoint antes del `flush()` (`$subscriptions->keyBy('endpoint')`)
y revisar si el report expone el endpoint directamente — ya avisaron que la API de
`minishlink/web-push` la escribieron de memoria, así que vale verificarlo en el mismo paso.

### D2 · Truncado del `body`

El gateway hace `json_encode` del contenido completo sin recortar. **El límite de un push es ~4 KB**,
y nuestro `alerts.text` puede traer N mensajes (por eso en el sync viaja como lista de strings).
Truncar el `body` y dejar el detalle completo para cuando se abre la app.

### D3 · `device_uuid` — ojo con el nombre

El `uuid` que mandamos en el POST es el de **la suscripción**, no el del dispositivo. Son dos cosas
distintas y en el payload del sync viajan las dos por separado (`device_uuid` a nivel raíz identifica
la instalación). Guardarlo en una columna llamada `device_uuid` funciona hoy, pero si alguna vez
implementan `POST /vets/{vet}/sync`, el `ack.push_subscriptions[].uuid` tiene que matchear **contra
esa columna**. Vale dejarlo anotado para no perder media hora en ese momento.

### D4 · `content_encoding`

Aceptar `aes128gcm` y `aesgcm` está bien, déjenlo así. No cuesta nada y nos deja margen si aparece
un navegador viejo.

---

## E · Circuito abierto de los dos lados: la confirmación

`require_confirmation` hoy hace **una sola cosa**: deja la notificación fija en pantalla (A2). Nada
más.

- **De nuestro lado:** `confirmed_at` existe en el modelo y en los types, pero no hay ni una ruta ni
  una acción de UI que lo escriba. Nunca se setea.
- **De su lado:** el plan no tiene endpoint de confirmación, y `alert_recipients.status` registra
  entrega, no confirmación del usuario.

O sea que hoy **nadie puede saber si el encargado vio y confirmó la alerta**, que es probablemente lo
que el negocio espera de una alerta marcada como "requiere confirmación".

No es un bloqueante del plan y no hace falta resolverlo en este PR. Pero si el producto lo necesita,
hace falta contrato nuevo: un endpoint para confirmar, y la devolución de `delivered_at` /
`confirmed_at` hacia el dispositivo — que es exactamente lo que alimenta el bloque `pull.alerts` de
la spec de sync que les pasamos.

**Pregunta concreta:** ¿el negocio necesita registrar la confirmación, o alcanza con que la
notificación quede fija? Según la respuesta, lo agendamos como trabajo aparte.

---

## F · Lo que cambiamos nosotros

**Nuestro parser esperaba una respuesta plana.** Nuestra documentación decía que el POST devolvía
`{"cloud_id": "42"}` y así lo parseábamos. Ustedes devuelven el envelope estándar de la v1
(`{success, data: {cloud_id}}`), que es lo correcto y consistente con el resto de la API — **no
hagan la excepción por nosotros**.

Ya lo ajustamos de nuestro lado para leer `data.cloud_id`. Sin ese cambio el push habría funcionado
igual (la fila queda creada), pero nosotros habríamos creído que la suscripción nunca se registró.

También ajustamos el service worker para resolver el `url` relativo de A3 contra el montaje real de
la app.

---

## Fuera de alcance, confirmado

- **`front/` no es la PWA.** Su lectura es correcta: la PWA es un codebase separado del monorepo
  (este). El módulo `front/src/modules/notifications` es la campanita in-app, otro concepto. No hay
  trabajo de frontend para ustedes en este plan.
- **El endpoint de la VAPID public key quedó descartado**, tal como dice el plan: va por env, se
  entrega una vez fuera de banda. Ver C1 sobre quién la genera.
- **`POST /vets/{vet}/sync` sigue pendiente y sin prioridad.** Está bien que este plan no lo toque.
  Lo único que perdemos mientras no exista es la red de contención: una suscripción que falla al
  registrarse no tiene reintento automático hasta que el usuario vuelva a activar las notificaciones.
  Es aceptable.
