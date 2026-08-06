# TKT-006 - Twilio no tiene webhook de estados ni manejo de opt-out entrante

## Tipo
Mejora (deuda tecnica funcional)

## Contexto
El subsistema de alertas salientes (`App\Notifications\*`) soporta dos proveedores intercambiables para `Channel::Whatsapp`, seleccionados por `WHATSAPP_PROVIDER`: `TwilioWhatsappGateway` y `KapsoWhatsappGateway`. Kapso tiene el circuito completo implementado (envio, webhook firmado, actualizacion de estados con precedencia, fallback a email). Twilio solo tiene la mitad: envia, pero nada vuelve.

Este ticket documenta la brecha para decidir despues si se cierra o si se descarta Twilio como proveedor.

## Estado actual

### Twilio: solo envia
- `TwilioWhatsappGateway::send()` funciona y devuelve `DeliveryResult::sent($sid)`.
- **No existe** `TwilioWebhookController`, ni ruta de webhook para Twilio en `back/routes/api/notifications-webhooks.php` (solo esta `POST v1/webhooks/kapso`), ni middleware de verificacion de firma equivalente a `VerifyKapsoWebhookSignature`.
- Consecuencia: con `WHATSAPP_PROVIDER=twilio` los `alert_recipients` quedan en `sent` para siempre. Nunca avanzan a `delivered`, `read` ni `failed` por webhook.
- Consecuencia derivada: el fallback a email por fallo reportado por el proveedor **nunca se dispara** en Twilio. Solo se dispara por fallo inmediato (4xx en el envio) o por agotamiento de reintentos en la cola.

### Nadie escribe la tabla `opt_outs`
- La tabla existe (`2026_07_24_000003_create_opt_outs_table.php`: `phone`, `channel`, unico por `(phone, channel)`).
- `OptOutPolicy` la lee y suprime correctamente (`DeliveryStatus::Suppressed`, sin fallback, por diseno).
- **Ninguna ruta de codigo escribe en ella.** No hay consumo de mensajes entrantes en ningun proveedor, asi que no hay forma de que un destinatario se dé de baja. Hoy solo se puede poblar a mano por SQL.
- El doc de diseno original (`docs/planes/arquitectura-notificaciones.md`) describia un flujo de "BAJA" por mensaje entrante de Twilio con normalizacion de WaId `549...` -> `54...` y eventos de dominio `RecipientOptedOut`/`RecipientOptedIn`. Nada de eso se implemento.

### `program.task_due` inutilizable en Twilio
`TWILIO_TEMPLATE_PROGRAM_TASK_DUE` esta vacio en `.env.example` con el comentario "Pending business approval". Sin ese `contentSid`, `TwilioWhatsappGateway::resolveContentSid()` tira `TemplateNotConfiguredException` (error definitivo, sin reintento, dispara fallback a email).

## Riesgo si no se atiende
- Riesgo legal/reputacional: sin opt-out funcional, el sistema no puede honrar una baja solicitada por un destinatario. En un sistema de mensajeria a productores esto no es opcional.
- Riesgo operativo: con Twilio activo no hay observabilidad de entrega. "Se envio" no es "llego", y hoy no se puede distinguir.

## Alcance confirmado

El usuario eligio el alcance mas amplio: **opt-out por Kapso + webhook de estados en Twilio**.
Se cierran los dos huecos en ambos proveedores.

Correccion a la premisa original de este ticket: el destino de Twilio **no bloqueaba** el
opt-out. `opt_outs` se indexa por `(phone, channel)` y el canal es `whatsapp`, no el proveedor
(`config/notifications.php` lo dice explicitamente: *"a provider is infrastructure; a channel is
business (it drives contact lookup, opt-outs and the fallback chain)"*). Una baja escrita desde
el webhook entrante de Kapso suprime tambien los envios por Twilio. Se implementa igual en
ambos por decision de alcance, no por necesidad tecnica.

## Restricciones tecnicas verificadas (leidas del codigo, no supuestas)

Estas son condiciones de diseno, no sugerencias. La primera invalida el diseno documentado.

### R1 (BLOQUEANTE) — El normalizador de entrada documentado rompe el opt-out en Argentina

`docs/planes/arquitectura-notificaciones.md:693-697` propone quitar el `9` de los moviles
argentinos en el ingreso:

```php
/** WhatsApp AR manda 549..., el numero real es 54... (quita el 9). */
return str_starts_with($waId, '549') ? '54' . substr($waId, 3) : $waId;
```

El lado de salida **no hace eso**. `AlertRecipient::normalizePhone()` (`AlertRecipient.php:86`)
es unicamente `preg_replace('/\D+/', '', $value)`: no toca el `9`, no toca el codigo de pais, no
toca el `0` inicial. Entonces:

- entrada segun el doc: `5491134290838` → `541134290838`
- salida real: contacto `+5491134290838` → `5491134290838`

`OptOutPolicy` compara con un `=` de SQL exacto. **Implementar el normalizador documentado
convierte cada opt-out argentino en un no-op permanente y silencioso.**

**Decision tecnica: la forma canonica es digitos-solo tal como los reporta Meta (`549...`), sin
quitar el `9`.** Justificacion: es la identidad con la que Meta entrega, es la que los gateways
ya usan para enviar con exito hoy (`KapsoWhatsappGateway` manda `to => $phone`,
`TwilioWhatsappGateway` manda `'whatsapp:+' . $phone`), y cualquier otra eleccion obliga a
transformar el lado que hoy funciona. El normalizador del doc de arquitectura se descarta.

### R2 (BLOQUEANTE) — No hay normalizador compartido

`normalizePhone()` es `private static` dentro de un modelo Eloquent
(`app/Notifications/Models/AlertRecipient.php:86`), con un unico call site en `toDto()`. Un
handler de webhook **no puede reusarlo**. Hay que extraerlo a un value object / clase de soporte
compartida, usada por: (a) `AlertRecipient::toDto()`, (b) el escritor de opt-out entrante, y
(c) idealmente un mutator en `Contact::value` mas migracion de datos.

Escribir `data_get($payload, '...from')` directo en `opt_outs.phone` es tirar una moneda.

### R3 (ALTO) — `contacts.value` no se valida en el camino que usa la app real

Dos regimenes distintos de validacion:

- `StoreContactRequest`/`UpdateContactRequest` (endpoints de contacto individual) aplican
  `preg_match('/^\+?[1-9]\d{7,14}$/')`. El `+` es opcional y el valor se guarda verbatim.
  `UpdateContactRequest` ademas **saltea la validacion por completo si el request no manda
  `type`**.
- Los **11 request classes** que validan `contacts.*.value` en arrays anidados (Vet, Client,
  VetStaff, ClientStaff, MyVetProfile...) usan `['required', 'string', 'max:200']` y nada mas.
  Ese es el camino que alimenta `ContactService::syncContacts()`, o sea el que crea los
  contactos que `AlertRecipient::toDto()` lee.

No hay normalizacion en escritura en ningun lado (`Contact` no tiene mutator ni cast sobre
`value`; `ContactService` pasa el valor tal cual). Hoy un contacto WhatsApp puede contener
`011 15-3429-0838`, que ni matchea un opt-out ni se puede enviar.

### R4 (MEDIO) — El contrato de `opt_outs.phone` no lo enforcea nada

La migracion declara `->comment('E.164 sin +, normalizado')`. Un comment de MySQL es metadata:
no valida nada, y en SQLite (la DB de tests) se descarta. El `unique(['phone','channel'])` sirve
para un `firstOrCreate` idempotente, pero tambien deja convivir `5491134290838` y `541134290838`
como dos filas independientes, ninguna de las cuales necesariamente matchea la salida.

Ningun test cubre un skew de formato: `OptOutPolicyTest` escribe y compara el mismo literal.
**La suite puede quedar verde con la feature rota.** Cualquier plan debe incluir un test de
formato desalineado.

### R5 (MEDIO) — El opt-out de email es irrepresentable

Para `Channel::Email`, `Recipient::$phone` es `null`, y Laravel convierte `where('phone', null)`
en `whereNull('phone')` sobre una columna `NOT NULL`: nunca matchea. `OptOutPolicy` esta
registrada para todos los canales sin distincion
(`NotificationServiceProvider.php:82-88`). Si el opt-out de email entra en alcance, la tabla
necesita otra forma de identificar al destinatario.

### R6 (A DEFINIR) — `opt_outs` no tiene scoping de tenant

No hay `vet_id`. Un telefono que se da de baja queda dado de baja para **todas** las
veterinarias. Hay que decidir si eso es correcto (defendible: la baja la pide la persona, no la
relacion comercial) o si la baja debe ser por vet.

### R7 — El evento entrante de Kapso no esta verificado

`whatsapp.message.received` aparece **una sola vez** en todo el repo, en
`.claude/docs/plans/kapso-whatsapp-provider-plan.md:603`, bajo "Extensiones futuras (fuera de
alcance)". No esta verificado contra el catalogo real de eventos de Kapso. Confirmar contra
`https://docs.kapso.ai/docs/platform/webhooks/event-types` antes de escribir codigo.

Ademas, la lista de eventos se fija **al registrar el webhook**: agregar el evento en el codigo
sin re-correr `kapso:register-webhook --update` da cero eventos entrantes. Y hay que tocar al
menos tres lugares: `KapsoRegisterWebhookCommand::EVENTS`,
`RecordDeliveryStatus::EVENT_STATUS` y `KapsoSimulateWebhookCommand`.

### R8 — Punto de enganche ya existente

Un `whatsapp.message.received` hoy se persistiria en `whatsapp_webhook_events` y despues
`RecordDeliveryStatus::apply()` lanzaria `UnsupportedWebhookEventException`, que
`ProcessKapsoWebhookEventJob` trata como definitivo (setea `processed_at` + `error`, sin
reintento). Conveniente como punto de enganche, pero **hay que ramificar antes** de
`RecordDeliveryStatus`, porque un mensaje entrante no tiene `message.id` correlacionable a
ningun recipient.

## Correccion: el estado real de la confirmacion

La version original de este ticket decia que `require_confirmation` y `confirmed_at` viven en
`alert_recipients` y que ninguna se escribe. Ambas afirmaciones son incorrectas:

- **`require_confirmation` NO esta en `alert_recipients`.** Esta en `alerts`
  (`2026_07_24_000001`) y en `protocol_task_alerts` (`2026_07_20_000003`). **Si se escribe**
  (`GenerateProgramTaskDueAlertsListener.php:86` la copia del protocolo a la alerta); lo que
  nunca ocurre es que alguien la **lea**.
- **`alert_recipients.confirmed_at` si existe y esta muerta de verdad**: aparece solo en
  `$fillable`, en los casts y en la migracion. Cero asignaciones, cero queries, cero tests.

## Decisiones que hay que tomar antes de implementar

### A definir 1: Se sigue soportando Twilio? (RESUELTA — si, se cierran los dos huecos)
La pregunta previa a todo lo demas. Tres caminos con tradeoffs reales:

- **Cerrar la brecha en Twilio**: implementar `TwilioWebhookController` + verificacion de firma (Twilio usa `X-Twilio-Signature`, HMAC-SHA1 sobre URL + params ordenados, distinto al esquema de Kapso) + mapeo de `MessageStatus` a `DeliveryStatus`. Costo alto, duplica el circuito ya resuelto en Kapso.
- **Deprecar Twilio**: dejar Kapso como unico proveedor real, y `fake` para desarrollo. Elimina la deuda de golpe. Requiere confirmar que no hay compromiso comercial con Twilio.
- **Dejarlo como esta y documentarlo**: aceptar que Twilio es "envio ciego". Solo viable si Twilio no se usa en produccion.

### A definir 2: Donde vive el opt-out
Independiente de la decision anterior, porque el opt-out hay que resolverlo igual. Sub-decisiones:
- Kapso ya expone `whatsapp.message.received`, pero `kapso:register-webhook` se suscribe deliberadamente solo a los cuatro eventos de estado de entrega (`sent`, `delivered`, `read`, `failed`), con el comentario "inbound messages are out of scope for the alerts pipeline". Ampliar la suscripcion es la via natural.
- Definir el vocabulario de baja (palabras aceptadas, case-insensitive, con o sin acentos) y si existe re-alta.
- Definir la normalizacion de telefono. La tabla comenta "E.164 sin +, normalizado", y `AlertRecipient::normalizePhone()` ya existe: hay que confirmar que ambos lados normalizan igual, o el opt-out no matchea nunca.
- Decidir si el opt-out se guarda por telefono (como hoy) o se liga tambien al `user_profile_id`. Por telefono es mas correcto (la baja la pide el numero, no el perfil) pero no cubre el canal email.

### A definir 3: Opt-out de email
`opt_outs.channel` admite cualquier `Channel`, pero no hay ningun mecanismo de baja para email (link de desuscripcion en `AlertMail`). Definir si entra en alcance.

## Restricciones
- No agregar un `Channel` nuevo por proveedor. Un proveedor es infraestructura, un canal es negocio: la regla ya esta documentada en `config/notifications.php` y no se toca.
- No cambiar el default de `contacts.use_for_alerts` (es un opt-in deliberado, ver TKT-007 y `ContactService`).
- Un opt-out NO debe caer al canal de fallback. La semantica actual es correcta y esta documentada: `Suppressed` no dispara fallback, a diferencia de `Failed`.
- Mantener idempotencia en cualquier webhook nuevo: la tabla `whatsapp_webhook_events` ya resuelve dedupe por `idempotency_key` unico, reusar ese mecanismo y no inventar otro.

## Investigacion previa que el arquitecto debe hacer
1. Confirmar con negocio si Twilio sigue en el roadmap o si se puede deprecar. Todo lo demas depende de esto.
2. Verificar si `TWILIO_TEMPLATE_PROGRAM_TASK_DUE` sigue pendiente de aprobacion o ya se resolvio.
3. Revisar el esquema de firma de webhook de Twilio y evaluar si conviene generalizar `VerifyKapsoWebhookSignature` a un middleware por proveedor o dejar dos middlewares separados.
4. Confirmar que `AlertRecipient::normalizePhone()` y el formato de `opt_outs.phone` coinciden exactamente, con un test que lo pruebe.
5. Revisar el plan `.claude/docs/plans/kapso-whatsapp-provider-plan.md`, que ya tiene como pendiente el consumo de `whatsapp.message.received` (hoy usado para cerrar `require_confirmation`/`confirmed_at`, que tampoco se escriben nunca). Evaluar si opt-out y confirmacion se resuelven en el mismo webhook entrante.

## Output esperado
Plan en `.claude/docs/plans/TKT-006-twilio-sin-webhook-ni-opt-out-plan.md`

## Referencias
- Guia de setup: `docs/guias/notificaciones-setup.md` (seccion 8.3 "Limitaciones conocidas de Twilio")
- `back/routes/api/notifications-webhooks.php`
- `back/app/Notifications/Gateways/Twilio/TwilioWhatsappGateway.php`
- `back/app/Notifications/Policies/OptOutPolicy.php`
- `back/app/Notifications/Http/Middleware/VerifyKapsoWebhookSignature.php`
- `back/database/migrations/2026_07_24_000003_create_opt_outs_table.php`
