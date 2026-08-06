# TKT-007 - docs/planes/arquitectura-notificaciones.md esta desactualizado y contradice el codigo

## Tipo
Mejora (deuda de documentacion)

## Contexto
`docs/planes/arquitectura-notificaciones.md` (748 lineas) es el diseno original del subsistema de alertas salientes. Buena parte se implemento tal cual, pero varios puntos quedaron superados por el plan posterior `.claude/docs/plans/kapso-whatsapp-provider-plan.md` y por decisiones de implementacion que nunca se reflejaron en el doc.

El problema no es que este viejo. El problema es que **describe cosas que no existen con la misma autoridad con la que describe cosas que si existen**, sin ninguna marca que las distinga. Un dev que lo lea como referencia de configuracion va a buscar clases inexistentes y a escribir queries contra columnas equivocadas.

## Estado actual

### Lo que el doc describe correctamente (implementado y vigente)
Los tres contratos, los tres enums (`Channel`, `DeliveryStatus`, `AlertType`) verbatim, `DeliveryPipeline` + `OptOutPolicy`, `GatewayRegistry`/`MessageBuilderRegistry` con bindings por tag, `DeliverAlertJob` con `$tries = 5` y `$backoff = [60, 300, 900, 1800]`, `DispatchDueAlerts` agendado cada minuto, `FakeGateway`, las tablas `alerts`/`alert_recipients`/`opt_outs`, y la estructura de carpetas.

### Lo que el doc describe y NO existe en el codigo
| El doc dice | La realidad |
| --- | --- |
| `alert_recipients.user_id` | La columna es **`user_profile_id`** (y la tabla tiene `guid`, que el doc no menciona) |
| `TemplateContent(string $templateId, array $variables, string $locale = 'es_AR')` | Superseded por DEC-03 del plan de Kapso: hoy es `TemplateContent(AlertType $type, array $variables)`, sin `$locale` |
| `'whatsapp' => ['sms', 'email']` como cadena de fallback | El real es `['email']` unicamente |
| Canal `sms` con `VonageSmsGateway::class` | **No existe esa clase.** `Channel::Sms` esta en el enum pero no tiene entrada en `config('notifications.channels')`: `GatewayRegistry::for(Channel::Sms)` tira excepcion |
| `TwilioWebhookController` en `App\Notifications\Webhooks` | No existe. No hay webhook de Twilio (ver TKT-006) |
| Eventos de dominio `RecipientOptedOut`, `RecipientOptedIn`, `DeliveryStatusUpdated` | No existe el directorio `Events/`. Ningun evento de dominio se emite |
| Normalizacion de WaId `549...` -> `54...` para dar de baja por mensaje "BAJA" | No implementado. Nada escribe `opt_outs` (ver TKT-006) |
| `QuietHoursPolicy`, `DuplicateSuppressionPolicy`, `RateLimitPolicy` | Solo `OptOutPolicy` esta taggeada en `alert.policies` |
| Tabla `user_notification_preferences` | No existe |
| `ProgramTaskMessageBuilder` con 5 `contentSid` hardcodeados por cantidad de mensajes | El builder real es `ProgramTaskDueMessageBuilder`. `.env.example` confirma que "the legacy 5-message-bundle catalog no longer applies" |
| `Relation::enforceMorphMap` con `health_plan` y `event` | `AppServiceProvider` usa `morphMap` **no enforcing**, con `vet`/`user_profile`/`client`/`program` |

### Divergencias adicionales entre el plan de Kapso y lo implementado
Menores, pero conviene reflejarlas para que el plan no quede como fuente enganosa:
- El plan proponia un `match` sin arm default en la seleccion de proveedor; la implementacion usa lookup por array (y ahora resuelve el error de forma diferida, ver el fix de esta misma tanda).
- El plan no incluia `KAPSO_BUSINESS_ACCOUNT_ID` ni `KAPSO_TEMPLATE_LANGUAGE`; ambos se implementaron.
- El plan decia que `NotificationServiceProvider` registra `ChannelFallbackService` y `RecordDeliveryStatus` como singletons; **no lo hace**, se autowirean.
- La tabla `whatsapp_webhook_events` del plan no tenia `payload_version` ni `outcome`, y daba a `provider` un default `'kapso'`; la tabla real agrega esas dos columnas y **no tiene default** en `provider`.
- Los tests que el plan listaba (`KapsoWebhookTest` de firma, `VerifyKapsoWebhookSignatureTest`) no cubren todo lo prometido.

## Decisiones que hay que tomar

### A definir 1: Que se hace con el doc
Tres caminos, en orden de preferencia sugerida:
- **Marcarlo como historico y no tocarlo mas**: agregar un banner al inicio que diga que es el diseno original de referencia, que puede contradecir el codigo, y que la fuente de verdad operativa es `docs/guias/notificaciones-setup.md`. Barato, honesto, cero riesgo. Mover a `docs/legacy/`.
- **Actualizarlo punto por punto**: mantiene un unico doc de arquitectura vigente. Costoso, y se va a volver a desactualizar salvo que alguien lo mantenga con disciplina.
- **Partirlo**: extraer lo que sigue vigente a un doc de arquitectura corto, y archivar el resto. Es el resultado mas util pero el que mas trabajo cuesta.

### A definir 2: Que hacer con lo que el doc propone y nunca se implemento
No todo es ruido. Hay ideas que pueden seguir siendo validas y que conviene rescatar antes de archivar el doc:
- `QuietHoursPolicy` (no mandar alertas de madrugada) — plausible y de valor real en el dominio.
- `DuplicateSuppressionPolicy` y `RateLimitPolicy` — evaluar si hacen falta.
- `user_notification_preferences` — preferencias por usuario y canal.

Decidir si cada una se descarta explicitamente o se convierte en su propio ticket. Lo que no se decide vuelve a aparecer como sorpresa en seis meses.

## Restricciones
- No borrar el doc sin dejar registro. Es el unico lugar donde esta escrito el razonamiento de varias decisiones que siguen vigentes.
- No "arreglar" el codigo para que coincida con el doc. En cada divergencia detectada, el codigo es la version deliberada y mas correcta; el doc es el que quedo atras.
- Si se archiva, mantener los enlaces desde `docs/guias/notificaciones-setup.md` (seccion 12) apuntando a la nueva ubicacion.

## Investigacion previa que el arquitecto debe hacer
1. Releer el doc completo y clasificar cada seccion en: vigente / superada / nunca implementada. La tabla de este ticket es un punto de partida, no un inventario exhaustivo.
2. Revisar tambien `.claude/docs/plans/kapso-whatsapp-provider-plan.md` con el mismo criterio: esta casi todo implementado, pero tiene pendientes reales (`WhatsappProviderResolver` para ruteo por pais, normalizacion de telefono en escritura, consumo de `whatsapp.message.received`, forwarding de `whatsapp.conversation.*` a soporte) que hoy no viven en ningun ticket.
3. Verificar si `docs/planes/reglas-negocio-alertas.md` y `docs/planes/diseno-protocolos-programas-alertas-v2.md` tienen el mismo problema de desactualizacion.

## Output esperado
Plan en `.claude/docs/plans/TKT-007-arquitectura-notificaciones-doc-desactualizado-plan.md`

## Referencias
- `docs/planes/arquitectura-notificaciones.md` (el doc en cuestion)
- `.claude/docs/plans/kapso-whatsapp-provider-plan.md`
- `docs/guias/notificaciones-setup.md` (seccion 12, donde ya se advierte la desactualizacion)
- Relacionado: TKT-006 (Twilio sin webhook ni opt-out), que cubre la parte funcional de lo que este doc describe y no existe
