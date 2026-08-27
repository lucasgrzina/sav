# QA Review — Backend: Web Push Notifications

Fecha: 2026-08-27
Scope: módulo self-service `PushSubscription` completo + cableado del canal `Channel::Push` en el pipeline `App\Notifications\*` existente (rewire de 3 listeners + `ProgramShareService`, `AlertRecipientFactory`, `WebPushGateway`, `PushContent`, 4 message builders, bindings, config, migración, tests). Se contrastó contra `.claude/docs/plans/web-push-notifications-plan.md` y `PUSH_PLAN_REVIEW.md`, y se corrió `php artisan test` dos veces (con y sin el cambio, vía `git stash`) para aislar fallas preexistentes.

## Resumen ejecutivo
- Críticos: 0
- Mayores: 0
- Menores: 1
- Estado: **APROBADO**

Implementación sólida y fiel al plan revisado. Los 7 puntos de riesgo específicos que se pidió confirmar quedaron todos verificados contra código real (no contra lo que dice el plan) y ninguno reveló un problema.

## Problemas críticos (bloquean merge)
Ninguno.

## Problemas mayores
Ninguno.

## Problemas menores

### [m-07 / observación] — Comentario de `WebPushGateway` describe una verificación que no corresponde a como quedó el código final
**Archivo**: `back/app/Notifications/Gateways/WebPush/WebPushGateway.php` línea 60-65
**Código actual**:
```php
// D1 (review de mobile): indexar por endpoint ANTES del flush, usando el accessor
// directo MessageSentReport::getEndpoint() (verificado contra el código fuente real
// de minishlink/web-push — existe y evita reconstruir la URI a mano desde
// getRequest()->getUri()). ...
```
Esto es correcto y está bien — lo marco solo como nota positiva, no como defecto: es exactamente el tipo de comentario "por qué" (m-07 en sentido inverso) que se pide en el checklist, y quedó actualizado respecto a la versión del plan que todavía dudaba de la API real. Sin acción requerida.

No se encontraron violaciones menores reales. Se documenta la entrada de todos modos porque el checklist pide "un 'sin problemas' es válido y valioso" — en este caso corresponde.

## Puntos de atención del usuario — verificación uno por uno

### 1. Multi-tenant — CONFIRMADO, sin fuga
`PushSubscriptionFactory`/`WebPushGateway`/`AlertRecipient::toPushDto()` consultan `PushSubscription::where('user_id', ...)` sin filtrar por `vet_id`, pero esto **no es una fuga**: el scoping por tenant ya ocurrió río arriba, en la resolución de `$program->managers` (siempre relativo a un `Program` de un `vet_id` concreto) antes de que `AlertRecipientFactory::createForManagers()` reciba la lista. `PushSubscription` es, por diseño (DEC-05), una entidad a nivel `User` — el mismo browser puede estar vinculado a varios tenants via distintos `UserProfile`, y el cruce correcto es `AlertRecipient.userProfile.user_id → PushSubscription.user_id`, que es exactamente lo que hace el código. Verifiqué los 3 listeners y `ProgramShareService::resolveValidRecipients()`: todos parten de `$program->managers`, nunca de una tabla global de usuarios. Correcto.

### 2. DEC-08 (gating por suscripción activa) — CONFIRMADO
`AlertRecipientFactory::createForManagers()` (`back/app/Notifications/Services/AlertRecipientFactory.php` líneas 32-34):
```php
if ($channel === Channel::Push && ! $this->hasActiveSubscription($manager)) {
    continue;
}
```
Si el manager no tiene suscripción activa, el `continue` salta el `AlertRecipient::create(...)` completo — no se crea la fila, no queda `Failed`. Confirmado también por test (`AlertRecipientFactoryTest::test_skips_the_push_channel_in_silence_for_managers_without_an_active_subscription`): con 2 managers y solo 1 con suscripción, se crean 3 filas (2 Whatsapp + 1 Push), nunca 4. Coincide exactamente con lo que pedía el review de mobile (sección C2) y con lo que el plan documenta como corrección aplicada.

### 3. `id` interno vs `guid` — CONFIRMADO, sin fugas
- `PushSubscriptionResource::toArray()` devuelve únicamente `['cloud_id' => $this->guid]` — no expone `id`.
- `PushSubscriptionController::store()` responde vía `ApiResponseTrait::makeSuccess()`, que envuelve en `{success, data}` estándar (mismo helper que usa el resto de la API) — el shape final es `{"success": true, "data": {"cloud_id": "<guid>"}}`, tal como pide el contrato con mobile.
- `PushSubscription` tiene `$hidden = ['id']` en el modelo (defensa en profundidad si algún día se serializa el modelo directo en vez del Resource).
- Test `PushSubscriptionControllerTest` corre `assertJsonMissing(['id'])` en las respuestas relevantes.
No se repitió el bug de `AuthService::formatUserPublic()`.

### 4. Rewire de los 3 listeners + `ProgramShareService` — CONFIRMADO, sin regresión oculta
Comparé `git diff` de `ProgramShareService.php`, `ProgramShareServiceTest.php` y `DeliverAlertJobTest.php` línea por línea:
- El cambio de firma del constructor de `ProgramShareService` es exactamente lo que dice el plan (DEC-09): se agrega `AlertRecipientFactory $recipientFactory` como segundo parámetro inyectado. El ajuste en `ProgramShareServiceTest::setUp()` es mecánico (agregar `new AlertRecipientFactory()` al `new ProgramShareService(...)`), no tapa ningún comportamiento distinto.
- El `foreach` inline que creaba `AlertRecipient` con `channel = Channel::Whatsapp` hardcodeado fue reemplazado 1:1 por `$this->recipientFactory->createForManagers($alert, $recipients)`, preservando el `DeliverAlertJob::dispatch()` inmediato por cada recipient creado (`$createdRecipients->each(...)`).
- El caso agregado en `DeliverAlertJobTest` (`test_delivers_a_pending_push_recipient_and_marks_it_sent`) es un caso **nuevo** de cobertura (canal Push vía `FakeGateway`), no una modificación de un test WhatsApp existente — no hay evidencia de que se haya "tapado" nada; el comportamiento WhatsApp existente no se tocó en ese archivo.
- Los 3 listeners (`GenerateProgramTaskDueAlertsListener`, `HandleProgramCancelledListener`, `ScheduleProgramCreatedAlertListener`) siguen el mismo patrón de reemplazo, todos preservando la resolución de `$program->managers`/`$recipients` sin cambios.

### 5. `minishlink/web-push` v9.0.4 — API real verificada, no alucinada
Se instaló `minishlink/web-push` `v9.0.4` (confirmado en `composer.lock`). Se leyó el código fuente real en `vendor/minishlink/web-push/src/MessageSentReport.php`:
- `isSuccess(): bool` — existe.
- `isSubscriptionExpired(): bool` — existe, implementado como `in_array($this->response->getStatusCode(), [404, 410], true)`.
- `getReason(): string` — existe.
- `getResponse(): ?ResponseInterface` — existe.
- `getEndpoint(): string` — existe (el gateway lo usa correctamente; la versión final del código **ya no** reconstruye la URI a mano vía `getRequest()->getUri()` como advertía la nota del plan original — usa el accessor directo, que es justo lo que D1 del review de mobile pedía confirmar).
- `Subscription::create(['endpoint', 'publicKey', 'authToken', 'contentEncoding'])` — firma verificada contra `vendor/minishlink/web-push/src/Subscription.php`, coincide.
Ningún método está inventado.

### 6. Truncado del `body` (~4KB / `BODY_MAX_LENGTH = 500`) — CONFIRMADO, se aplica ANTES de armar el payload
`WebPushGateway::send()` línea 42: `Str::limit($content->body, self::BODY_MAX_LENGTH)` ocurre dentro del `json_encode([...])` que arma `$payload`, antes del loop que llama a `queueNotification()`. El truncado nunca llega sin aplicar al cliente HTTP. Test `test_body_longer_than_the_limit_is_truncated_while_the_rest_stays_intact` lo cubre explícitamente, verificando que `title`/`tag`/`url` quedan intactos y solo `body` se recorta.

### 7. Los 13 tests "fallando en baseline" — VERIFICADO, no es un supuesto del reporte de dev-backend
Corrí `php artisan test` con el cambio completo: **13 failed, 1 skipped, 378 passed** (9 en `TwilioWebhookTest`, 4 en `ProgramAlertGenerationTest`). Luego hice `git stash` de únicamente los archivos modificados (dejando los archivos nuevos del módulo Push intactos porque ninguno de los dos test suites en cuestión los usa) y corrí `php artisan test --filter="TwilioWebhookTest|ProgramAlertGenerationTest"` sobre el código previo al cambio: **mismos 13 failed**, con los mismos mensajes exactos (`Expected response status code [204] but received 401` en Twilio, `ModelNotFoundException` en 3 de los 4 de `ProgramAlertGenerationTest`). Confirmado con `git stash pop` para restaurar el estado. Son fallas preexistentes, no relacionadas con este cambio — el reporte de `dev-backend` es correcto. (No se investigó la causa raíz de esas 13 fallas preexistentes porque está fuera del scope de esta revisión — quedan como deuda técnica documentada, no bloqueante para este PR.)

## Verificaciones cruzadas

- **Resource vs Controller (campos consistentes)**: OK. `PushSubscriptionResource` expone `cloud_id`; el controller lo envuelve en `{success, data}` sin agregar/quitar campos.
- **FormRequest vs Migración (campos validados existen en tabla)**: OK. `uuid`→`device_uuid`, `endpoint`→`endpoint`, `keys.p256dh`→`p256dh`, `keys.auth`→`auth_key`, `content_encoding`→`content_encoding`, `device_label`→`device_label`, `updated_at`→`device_updated_at`. Todos los campos de `StorePushSubscriptionRequest::rules()` tienen columna destino en la migración, mapeados explícitamente en `PushSubscriptionService::subscribe()`.
- **Binding en AppServiceProvider**: OK. `PushSubscriptionRepositoryInterface::class => PushSubscriptionRepositoryEloquent::class` presente en `register()`, con los `use` correspondientes.
- **Rutas incluidas en api.php**: OK (indirecto). `back/routes/api/push-subscriptions.php` sigue el patrón kebab-case ya usado por las demás 21 rutas del directorio, incluidas automáticamente por el `glob()` existente en `back/routes/api.php` — no hace falta tocar ese archivo (confirmado que es el patrón real del proyecto, no una suposición del plan).
- **4 permisos en PermissionSeeder**: N/A intencional — DEC-03 documenta explícitamente que este módulo self-service no lleva permisos Spatie (mismo precedente que `UserSettingController`/`NotificationController`). Verificado: `PermissionSeeder.php` no tiene ninguna entrada `push.*`. Correcto, no es una omisión.
- **`getRouteKeyName()` en modelo nuevo (M-02)**: no está presente en `PushSubscription`, pero no aplica — ningún endpoint usa route model binding sobre este modelo (`store`/`destroy` resuelven todo vía `$request->user()` + `endpoint` del body, nunca un `{push_subscription}` en la URL). Mismo patrón que el precedente `UserSetting` (que ni siquiera usa `HasGuid`). No es una violación real de la convención — la convención asume rutas con binding por `guid`, que este módulo no tiene.

## Archivos revisados
- `back/app/Models/PushSubscription.php`
- `back/app/Contracts/Repositories/PushSubscriptionRepositoryInterface.php`
- `back/app/Repositories/PushSubscriptionRepositoryEloquent.php`
- `back/database/migrations/2026_08_25_000001_create_push_subscriptions_table.php`
- `back/app/Http/Requests/StorePushSubscriptionRequest.php`
- `back/app/Http/Requests/DeletePushSubscriptionRequest.php`
- `back/app/Http/Resources/V1/PushSubscriptionResource.php`
- `back/app/Services/PushSubscriptionService.php`
- `back/app/Http/Controllers/V1/PushSubscriptionController.php`
- `back/routes/api/push-subscriptions.php`
- `back/app/Providers/AppServiceProvider.php`
- `back/app/Notifications/NotificationServiceProvider.php`
- `back/config/notifications.php`
- `back/.env.example`
- `back/composer.json` / `back/composer.lock`
- `back/app/Notifications/Data/PushContent.php`
- `back/app/Notifications/Services/AlertRecipientFactory.php`
- `back/app/Notifications/Gateways/WebPush/WebPushGateway.php`
- `back/app/Notifications/Models/AlertRecipient.php`
- `back/app/Notifications/Builders/ProgramCreatedMessageBuilder.php`
- `back/app/Notifications/Builders/ProgramCancelledMessageBuilder.php`
- `back/app/Notifications/Builders/ProgramTaskDueMessageBuilder.php`
- `back/app/Notifications/Builders/ProgramPdfShareMessageBuilder.php`
- `back/app/Listeners/GenerateProgramTaskDueAlertsListener.php`
- `back/app/Listeners/HandleProgramCancelledListener.php`
- `back/app/Listeners/ScheduleProgramCreatedAlertListener.php`
- `back/app/Services/ProgramShareService.php`
- `back/tests/Feature/PushSubscriptionControllerTest.php`
- `back/tests/Feature/ProgramCreatedAlertRecipientsTest.php`
- `back/tests/Unit/Notifications/AlertRecipientFactoryTest.php`
- `back/tests/Unit/Notifications/AlertRecipientToDtoPushTest.php`
- `back/tests/Unit/Notifications/WebPushGatewayTest.php`
- `back/tests/Unit/Notifications/DeliverAlertJobTest.php` (diff)
- `back/tests/Unit/ProgramShareServiceTest.php` (diff)
- `back/vendor/minishlink/web-push/src/MessageSentReport.php` (código fuente real, no el plan)
- `back/vendor/minishlink/web-push/src/Subscription.php` (código fuente real, no el plan)
- `back/app/Notifications/Contracts/NotificationChannelGateway.php`
- `back/app/Notifications/Data/Recipient.php`
- `back/app/Repositories/BaseRepositoryEloquent.php`
- `back/app/Models/UserSetting.php` (precedente para el gap de `getRouteKeyName`)
- `back/database/seeders/PermissionSeeder.php` (cross-check DEC-03)

## Verificación técnica (composer test)
`php artisan test` completo: **378 passed, 13 failed, 1 skipped**. Los 13 fallos (`TwilioWebhookTest` ×9, `ProgramAlertGenerationTest` ×4) se reprodujeron de forma idéntica corriendo la misma suite sobre el código previo al cambio (`git stash` de los archivos modificados). Preexistentes, no bloqueantes para este PR.
