# Plan técnico: Planes Sanitarios — Fase 2 (alertas automáticas mensuales `HealthPlanMonth`)

## Input procesado

`.claude/docs/specs/planes-sanitarios-alertas-fase2-spec.md` (agente `funcional`), con las 5 decisiones de negocio DU2-01..DU2-05 ya cerradas (no se reabren). Contexto adicional: `.claude/docs/specs/planes-sanitarios-establecimiento-spec.md` y `.claude/docs/plans/planes-sanitarios-establecimiento-fase1-plan.md` (Fase 1, ya implementada en código real — verificado, no solo el plan).

## Resumen ejecutivo

Se conecta `EstablishmentHealthPlan`/`EstablishmentHealthPlanActivity` (Fase 1) al motor de alertas real (`Alert`/`AlertRecipient`/`DispatchDueAlerts`/`DeliverAlertJob`/gateways) usando `AlertType::HealthPlanMonth`, ya reservado en el enum. La generación es un listener síncrono sobre un evento de dominio nuevo, disparado al instanciar el plan (no un scheduler nuevo — `alerts:dispatch-due`, que ya corre cada minuto, es agnóstico al tipo de alerta y no requiere cambios para el envío). La pieza de diseño central es RF-02 (payload recalculado al momento del envío, con posibilidad de abortar el envío): se resuelve con dos cambios quirúrgicos y acotados al pipeline genérico — `AlertMessageBuilder::build()` pasa a devolver `?MessageContent`, y `DeliverAlertJob` trata un `null` como supresión (`DeliveryStatus::Suppressed`) sin tocar gateways ni `DeliveryPipeline`. Sin migraciones nuevas: `alerts`/`alert_recipients` ya son genéricas.

## Decisiones tomadas

**DEC2-01 — Trigger de generación: evento síncrono en `EstablishmentHealthPlanService::create()`, no scheduler nuevo**
Decisión: nuevo evento `EstablishmentHealthPlanInstantiatedEvent` disparado con `event()` dentro de la misma `DB::transaction()` de `create()` (mismo patrón que `ProgramTargetsChangedEvent` en `ProgramService::create()`), manejado por un listener síncrono (sin `ShouldQueue`, igual que `GenerateProgramTaskDueAlertsListener`).
Justificación: RF-01 pide generar las alertas "al instanciar el plan", no por barrido periódico. `alerts:dispatch-due` (`Schedule::command('alerts:dispatch-due')->everyMinute()`, verificado en `back/routes/console.php`) ya despacha CUALQUIER `Alert` con `status=pending` y `scheduled_at<=now()` sin importar su `type` — es 100% genérico y no necesita saber que existe `HealthPlanMonth`. Un comando nuevo de barrido duplicaría responsabilidad sin necesidad.
Alternativa descartada: comando `health-plans:generate-month-alerts` corrido por cron — necesario solo si la generación tuviera que repetirse periódicamente (ej. por edición del plan), pero Fase 1 no tiene edición (`EstablishmentHealthPlan` es snapshot, solo instanciar/cancelar — confirmado en spec Fase 1, "Fuera de alcance"). Un evento en la creación cubre el 100% de los casos reales.

**DEC2-02 — Recálculo/aborto en destino: `AlertMessageBuilder::build()` nullable + nuevo hook en `DeliverAlertJob`**
Decisión: cambiar la firma de `App\Notifications\Contracts\AlertMessageBuilder::build()` de `MessageContent` a `?MessageContent`. `HealthPlanMonthMessageBuilder::build()` consulta el estado ACTUAL de `EstablishmentHealthPlanActivity` (no el payload congelado del `Alert`) y devuelve `null` si no queda ninguna `pending` para ese `(plan, mes)`. En `DeliverAlertJob::handle()`, justo después de `$content = $builders->for(...)->build(...)`, si `$content === null` se marca `$recipient->status = DeliveryStatus::Suppressed`, `failure_reason = 'no_longer_applicable'`, sin llamar al gateway ni al `DeliveryPipeline`, y se retorna (mismo temprano-return que ya usan los otros casos de `DeliverAlertJob`, sin fallback — igual que la supresión por opt-out, es una decisión de contenido, no una falla técnica).
Justificación — por qué NO usar `DeliveryPolicy`/`SuppressionReason` (el otro mecanismo de aborto que ya existe en el pipeline): `DeliveryPolicy::check(OutboundMessage $message)` solo recibe `recipient`, `content`, `channel`, `idempotencyKey` — nunca el `Alert` ni su `subject`. Para decidir "¿quedan actividades pendientes de este `(plan, mes)`?" hace falta el `Alert->subject` (el `EstablishmentHealthPlan`) y su relación `activities()`, dato que SOLO tiene el builder (es quien recibe `$alert` completo). Forzar esa lógica a un `DeliveryPolicy` requeriría ensanchar `OutboundMessage` con el `Alert` completo, lo cual filtra una responsabilidad de negocio (¿aplica este contenido?) a un componente diseñado para políticas transversales de entrega (opt-out, quiet hours, rate limit) — no es su lugar natural.
Alternativa descartada: mantener `build()` no-nullable y hacer que devuelva un `MessageContent` "vacío" marcador (ej. `NullContent implements MessageContent`), detectado luego por un nuevo `DeliveryPolicy`. Descartada por dos motivos: (1) sigue sin resolver que el `DeliveryPolicy` no tiene acceso al `Alert`/subject — tendría que inspeccionar el contenido armado, acoplando el policy al shape interno de un builder específico; (2) es más código (una clase marcadora + un policy nuevo) para el mismo resultado que una firma nullable ya idiomática en PHP. El cambio de interfaz es retrocompatible: PHP permite que una implementación (`ProgramTaskDueMessageBuilder`, etc.) declare un tipo de retorno no-nulo como subtipo covariante de `?MessageContent` sin tocar ninguno de los 4 builders existentes.
Precedente reutilizado: `DeliveryStatus::Suppressed` y el patrón de `failure_reason` como string libre (no enum-cast en la migración de `alert_recipients` — verificado) ya existen; no hace falta tocar `SuppressionReason` (ese enum es específico de `DeliveryPolicy`, un concepto distinto).

**DEC2-03 — Subject polimórfico: `Alert.subject` = `EstablishmentHealthPlan`, mes en el `payload`**
Decisión: cada `Alert` de tipo `HealthPlanMonth` tiene `subject_type='establishment_health_plan'` / `subject_id=<plan.id>` (vía `Relation::morphMap`, nueva entrada) y `payload = {"month": <int 1-12>}`. El payload NO incluye la lista de actividades ni sus guids — a propósito, porque RF-02 exige recalcularlas en destino; guardar una lista ahí sería el "payload congelado" que la spec pide evitar.
Justificación: un plan puede tener N `Alert` (una por mes), todas con el mismo `subject_id`. Esto es válido con `MorphTo` (no es una relación 1:1) y es exactamente el mismo patrón que ya usa `AlertType::ProgramTaskDue`, donde varias `Alert` cuelgan del mismo `Program` (una por tarea×alerta de protocolo, verificado en `GenerateProgramTaskDueAlertsListener`).
Alternativa descartada: subject = una `EstablishmentHealthPlanActivity` puntual — incorrecto, la alerta agrupa VARIAS actividades de un mismo mes (DU2-01), no representa una sola.

**DEC2-04 — Destinatarios: nuevo método de repositorio, no reutilizar `managers()` (no existe para `EstablishmentHealthPlan`)**
Decisión: agregar `UserProfileRepositoryInterface::listByRoleForVet(Vet $vet, string $roleName): Collection`, implementado en `UserProfileRepositoryEloquent` con `whereHas('role', fn ($q) => $q->where('name', $roleName))->where('authenticatable_type', 'vet')->where('authenticatable_id', $vet->id)->with(['user', 'role'])->get()`. El listener lo invoca como `listByRoleForVet($plan->vet, 'vet')`.
Justificación: `Program` resuelve destinatarios vía una relación `belongsToMany(UserProfile::class, 'program_manager')` explícita (el vet elige managers al crear el programa) — `EstablishmentHealthPlan` NO tiene ese concepto (DU2-02 ya fija el destinatario por ROL, no por selección manual). El precedente de código correcto para "todos los perfiles de un rol dado en un vet" es `UserProfileRepositoryEloquent::listOwnersForClient()` (ya existe, filtra `whereHas('role', ...)` sobre `client`) — se generaliza el mismo patrón a `vet` con el nombre de rol como parámetro, en vez de hardcodear un método `listVetRoleProfilesForVet` de un solo uso.
Alternativa descartada: filtrar por rol en el listener con `Role::where('name','vet')->...->get()` directo desde el modelo, sin pasar por el repositorio — rompe la convención de capas del proyecto (`backend-conventions.md`: el Service/Listener no consulta modelos directamente para queries de negocio, usa el Repository).

**DEC2-05 — Timezone: mismo comportamiento "naive" que el legado, limitación conocida y NO resuelta en esta fase**
Decisión: `scheduled_at` se calcula con `HealthPlanYear::dateForMonth($country, $plan->year, $month)->subDays(7)->setTime(16, 0)`, usando `Carbon` sin conversión explícita de timezone (mismo comportamiento que `GenerateProgramTaskDueAlertsListener::generateAlertForProtocolTaskAlert()`, que hace `Carbon::parse($alertDate->toDateString().' '.$protocolTaskAlert->time)` sin especificar zona horaria, verificado en código — la app corre con `config('app.timezone') = 'UTC'`, verificado en `back/config/app.php`).
Justificación: es el comportamiento YA EXISTENTE en el pipeline de `ProgramTaskDue` — Fase 2 no introduce una regresión nueva, hereda la misma limitación documentada en la spec funcional ("timezone fijo `America/Argentina/Buenos_Aires`... queda como riesgo a resolver por arquitecto... no lo resuelvas para multi-país ahora salvo que sea trivial"). Resolverlo de verdad requeriría decidir en qué timezone se interpreta "16:00hs" por país y convertir a UTC al persistir — no es trivial (afectaría también a `ProgramTaskDue` para ser consistente) y excede el alcance de esta feature.
Queda documentado en Riesgos como deuda preexistente, no nueva.

**DEC2-06 — Cancelación: evento + listener propios, análogos a `ProgramCancelledEvent`/`HandleProgramCancelledListener`**
Decisión: nuevo `EstablishmentHealthPlanCancelledEvent`, disparado dentro de `EstablishmentHealthPlanService::cancel()`, manejado por `CancelHealthPlanMonthAlertsListener` que borra (hard delete, mismo criterio que `HandleProgramCancelledListener`) las `Alert` `type=HealthPlanMonth`, `subject_type=establishment_health_plan`, `subject_id=$plan->id`, `status=pending`.
Justificación: DU2-04 pide exactamente el mismo efecto colateral que `ProgramCancelled` sobre `ProgramTaskDue` — se replica el patrón verbatim. No hace falta filtrar por "mes futuro" porque, por RF-01 (descarte en creación de alertas cuya fecha ya pasó), TODA `Alert` `HealthPlanMonth` en estado `pending` es, por construcción, de un mes aún no disparado — el filtro `status=pending` ya es equivalente a "mes futuro".
Alternativa descartada: no generar un evento nuevo y llamar al borrado directo dentro de `EstablishmentHealthPlanService::cancel()` — rompe la separación de responsabilidades que ya usa `ProgramService::cancel()` (el Service de dominio no conoce el modelo `Alert` ni el motor de notificaciones; esa integración vive en el listener, en el namespace de `Notifications`).

**DEC2-07 — Plantilla de WhatsApp: se agrega la entrada al catálogo y a la config de ambos proveedores; el `contentSid`/aprobación real de Meta es una tarea operativa fuera de este plan**
Decisión: agregar `AlertType::HealthPlanMonth` a `WhatsappTemplateCatalog::definitions()` con 7 variables posicionales (mismo shape que el legado: destinatario, mes, actividades, nombre del plan, categoría, cliente, establecimiento), agregar las claves de config correspondientes en `notifications.twilio.templates`/`notifications.kapso.templates` (con sus env vars), y agregar la entrada al allowlist `TwilioCreateTemplatesCommand::TEMPLATES` (Kapso NO necesita allowlist — `KapsoCreateTemplatesCommand::payloads()` ya itera TODO `WhatsappTemplateCatalog::definitions()`, verificado en código).
Justificación: sin la entrada en el catálogo, `TwilioWhatsappGateway::resolveContentSid()` lanza `TemplateNotConfiguredException` en cuanto `DeliverAlertJob` intente enviar por WhatsApp (canal por defecto, `default_channels = [Whatsapp, Push]`, verificado en `config/notifications.php`) — ese caso ya está manejado con gracia por el pipeline existente (se loguea, se marca `Failed`, se intenta fallback a email), así que no bloquea el envío por otros canales, pero sin la entrada el canal WhatsApp de esta alerta NUNCA funcionaría ni con la infraestructura de aprovisionamiento correcta. El aprovisionamiento real (`php artisan twilio:create-templates` / `kapso:create-templates`, y la aprobación de Meta/Twilio del contenido) es un paso operativo de despliegue, no algo que el código pueda resolver — se documenta como pendiente en "Orden de implementación" y "Pendientes".
Alternativa descartada: no tocar el catálogo hasta tener el `contentSid` real aprobado — dejaría el canal WhatsApp roto silenciosamente en producción (fallback a email cada vez, sin que nadie note por qué) en vez de fallar de forma explícita y accionable vía el comando de aprovisionamiento existente.

**DEC2-08 — `require_confirmation` del `Alert` en `false` (default), no confundir con la confirmación de la actividad**
Decisión: la `Alert` `HealthPlanMonth` se crea sin setear `require_confirmation` (queda en el default `false` de la migración). La confirmación de negocio sigue siendo exclusivamente sobre `EstablishmentHealthPlanActivity.confirmed_at`/`confirmed_by_profile_id` (endpoint ya existente de Fase 1, `POST .../activities/{activityGuid}/confirm`).
Justificación: `Alert.require_confirmation` es un campo del pipeline de notificaciones (afecta al payload push, ver `PushContent.data['requires_confirmation']` en `ProgramTaskDueMessageBuilder`) pensado para alertas donde la ACCIÓN de confirmar ocurre sobre la alerta misma (ej. `ProtocolTaskAlert.require_confirmation`). Acá la confirmación ya tiene su propio mecanismo (Fase 1, regla dura #7) sobre un modelo distinto — mezclar ambos conceptos generaría dos fuentes de verdad para "¿está confirmado?".

## Cambios en BACKEND

### Migrations

Ninguna. `alerts`/`alert_recipients` ya son tablas genéricas (`type` string, `subject` polimórfico, `payload` json) — no requieren columnas nuevas para soportar `HealthPlanMonth`.

### Archivos a modificar

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** agregar entrada al `Relation::morphMap()` existente (método `boot()`).
**Antes:**
```php
Relation::morphMap([
    'vet'          => Vet::class,
    'user_profile' => UserProfile::class,
    'client'       => Client::class,
    'program'      => Program::class,
]);
```
**Después:** agregar `'establishment_health_plan' => EstablishmentHealthPlan::class,` (+ `use App\Models\EstablishmentHealthPlan;` en el bloque de imports).

#### `back/app/Notifications/Contracts/AlertMessageBuilder.php`
**Cambio:** `build()` pasa a retornar `?MessageContent` en vez de `MessageContent`.
**Antes:** `public function build(Alert $alert, Recipient $recipient): MessageContent;`
**Después:** `public function build(Alert $alert, Recipient $recipient): ?MessageContent;` — agregar docblock: `/** @return MessageContent|null null si, al recalcular en destino, la alerta ya no debe enviarse (ver DeliverAlertJob). */`. Sin impacto en los 4 builders existentes (retorno covariante).

#### `back/app/Notifications/Jobs/DeliverAlertJob.php`
**Cambio:** tras construir `$content`, chequear `null` antes de armar `OutboundMessage`.
**Antes (línea ~67):**
```php
$content = $builders->for($recipient->alert->type)->build($recipient->alert, $recipientDto);
$message = new OutboundMessage(
    $recipientDto, $content, $recipient->channel, $recipient->idempotency_key,
);
```
**Después:**
```php
$content = $builders->for($recipient->alert->type)->build($recipient->alert, $recipientDto);

if ($content === null) {
    // El builder recalculó el contenido en destino (ver HealthPlanMonthMessageBuilder) y
    // determinó que la alerta ya no aplica (ej. todas las actividades se confirmaron entre
    // la creación de la Alert y este envío). No es un fallo técnico: no hay fallback.
    $recipient->update(['status' => DeliveryStatus::Suppressed, 'failure_reason' => 'no_longer_applicable']);

    return;
}

$message = new OutboundMessage(
    $recipientDto, $content, $recipient->channel, $recipient->idempotency_key,
);
```

#### `back/app/Notifications/NotificationServiceProvider.php`
**Cambio:** agregar `HealthPlanMonthMessageBuilder::class` al array de `$this->app->tag([...], 'alert.builders')` + `use App\Notifications\Builders\HealthPlanMonthMessageBuilder;`.

#### `back/app/Notifications/Templates/WhatsappTemplateCatalog.php`
**Cambio:** agregar entrada `AlertType::HealthPlanMonth->value` a `definitions()`:
```php
AlertType::HealthPlanMonth->value => [
    'body' => 'Hola {{1}}, en {{2}} corresponde: {{3}} — plan "{{4}}" ({{5}}) de {{7}}, {{6}}.',
    'examples' => ['Lucas', 'julio', 'Vacunación Aftosa, Desparasitación', 'Plan Ganadero Anual', 'Bovinos', 'Estancia La Esperanza', 'Establecimiento Norte'],
],
```
(7 variables: 1=destinatario, 2=mes, 3=actividades, 4=nombre del plan/template, 5=categoría, 6=cliente, 7=establecimiento — mismo orden que consume `HealthPlanMonthMessageBuilder`.)

#### `back/config/notifications.php`
**Cambio:** agregar bajo `'twilio.templates'`:
```php
'health_plan.month' => env('TWILIO_TEMPLATE_HEALTH_PLAN_MONTH'),
```
y bajo `'kapso.templates'`:
```php
'health_plan.month' => [
    'name' => env('KAPSO_TEMPLATE_HEALTH_PLAN_MONTH', 'sav_health_plan_month'),
    'language' => env('KAPSO_TEMPLATE_LANGUAGE', 'es'),
],
```

#### `back/app/Console/Commands/TwilioCreateTemplatesCommand.php`
**Cambio:** agregar a `self::TEMPLATES`:
```php
'health_plan.month' => ['env' => 'TWILIO_TEMPLATE_HEALTH_PLAN_MONTH', 'friendly_name' => 'sav_health_plan_month'],
```
(`KapsoCreateTemplatesCommand` NO requiere cambios — itera automáticamente todo `WhatsappTemplateCatalog::definitions()`.)

#### `back/app/Contracts/Repositories/UserProfileRepositoryInterface.php` + `back/app/Repositories/UserProfileRepositoryEloquent.php`
**Cambio:** agregar método nuevo.
```php
/** @return Collection<int, UserProfile> perfiles del vet con el rol dado (ej. 'vet') — DU2-02, regla dura #4. */
public function listByRoleForVet(Vet $vet, string $roleName): Collection;
```
Implementación (mismo patrón que `listOwnersForClient`):
```php
public function listByRoleForVet(Vet $vet, string $roleName): Collection
{
    return $this->newQuery()
        ->with(['user', 'role'])
        ->whereHas('role', fn ($q) => $q->where('name', $roleName))
        ->where('authenticatable_type', 'vet')
        ->where('authenticatable_id', $vet->id)
        ->get();
}
```

#### `back/app/Services/EstablishmentHealthPlanService.php`
**Cambio 1 — `create()`:** ampliar el `->load([...])` final para incluir `client.country` (lo necesita el listener para `HealthPlanYear::dateForMonth`, evita una query N+1 lazy) y disparar el evento antes del `return`, dentro de la misma transacción.
**Antes:**
```php
return $plan->fresh()->load(['client', 'establishment', 'template.category', 'activities.activity']);
```
**Después:**
```php
$plan = $plan->fresh()->load(['client.country', 'establishment', 'template.category', 'activities.activity']);

event(new EstablishmentHealthPlanInstantiatedEvent($plan));

return $plan;
```
**Cambio 2 — `cancel()`:** disparar el evento de cancelación dentro de la transacción.
**Antes:**
```php
return DB::transaction(function () use ($plan) {
    return $this->repository->update($plan, ['cancelled_at' => now()]);
});
```
**Después:**
```php
return DB::transaction(function () use ($plan) {
    $plan = $this->repository->update($plan, ['cancelled_at' => now()]);

    event(new EstablishmentHealthPlanCancelledEvent($plan));

    return $plan;
});
```
(+ `use App\Events\EstablishmentHealthPlanInstantiatedEvent;` y `use App\Events\EstablishmentHealthPlanCancelledEvent;` en los imports.)

### Archivos a crear

#### `back/app/Events/EstablishmentHealthPlanInstantiatedEvent.php`
**Propósito:** dispara la generación de alertas mensuales. Mismo esqueleto que `ProgramTargetsChangedEvent`.
```php
class EstablishmentHealthPlanInstantiatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EstablishmentHealthPlan $plan) {}
}
```

#### `back/app/Events/EstablishmentHealthPlanCancelledEvent.php`
**Propósito:** dispara la cancelación de alertas pendientes. Mismo esqueleto que `ProgramCancelledEvent`.
```php
class EstablishmentHealthPlanCancelledEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EstablishmentHealthPlan $plan) {}
}
```

#### `back/app/Listeners/GenerateHealthPlanMonthAlertsListener.php`
**Propósito:** RF-01. Análogo a `GenerateProgramTaskDueAlertsListener`, agrupando por mes en vez de iterar tareas.
**Firma principal:**
```php
class GenerateHealthPlanMonthAlertsListener
{
    public function __construct(
        private readonly UserProfileRepositoryInterface $userProfiles,
        private readonly AlertRecipientFactory $recipientFactory,
    ) {}

    public function handle(EstablishmentHealthPlanInstantiatedEvent $event): void
    {
        $plan = $event->plan;
        $country = $plan->client->country;
        $months = $plan->activities->pluck('month')->unique()->sort()->values();

        if ($months->isEmpty()) {
            return; // RF-01 AC#4 — plan sin actividades materializadas (caso borde defensivo)
        }

        $recipients = $this->userProfiles->listByRoleForVet($plan->vet, 'vet'); // DU2-02

        if ($recipients->isEmpty()) {
            return; // sin destinatarios, no tiene sentido crear la Alert
        }

        foreach ($months as $month) {
            $this->generateAlertForMonth($plan, $country, (int) $month, $recipients);
        }
    }

    private function generateAlertForMonth(EstablishmentHealthPlan $plan, Country $country, int $month, Collection $recipients): void
    {
        $scheduledAt = HealthPlanYear::dateForMonth($country, $plan->year, $month)
            ->subDays(7)
            ->setTime(16, 0);

        if ($scheduledAt->isPast()) {
            return; // RF-01 AC#3 — descarte silencioso, sin log (mismo criterio que ProgramTaskDue)
        }

        $alert = new Alert([
            'type' => AlertType::HealthPlanMonth,
            'payload' => ['month' => $month],
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'vet_id' => $plan->vet_id,
        ]);
        $alert->subject()->associate($plan);
        $alert->save();

        $this->recipientFactory->createForManagers($alert, $recipients);
    }
}
```
Nota: `AlertRecipientFactory::createForManagers()` ya acepta `iterable<UserProfile>` genérico (no está acoplado a `program_manager`) — se reutiliza sin cambios, igual que hacen `GenerateProgramTaskDueAlertsListener` y `HandleProgramCancelledListener`.

#### `back/app/Listeners/CancelHealthPlanMonthAlertsListener.php`
**Propósito:** RF-04. Análogo a `HandleProgramCancelledListener` (sin la parte de crear una alerta de "cancelado" — DU2-04 no pide notificar la cancelación, solo pide borrar las pendientes).
```php
class CancelHealthPlanMonthAlertsListener
{
    public function handle(EstablishmentHealthPlanCancelledEvent $event): void
    {
        Alert::query()
            ->where('type', AlertType::HealthPlanMonth)
            ->where('subject_type', 'establishment_health_plan')
            ->where('subject_id', $event->plan->id)
            ->where('status', 'pending')
            ->get()
            ->each(fn (Alert $pending) => $pending->delete());
    }
}
```

#### `back/app/Notifications/Data/Payloads/HealthPlanMonthPayload.php`
**Propósito:** DTO tipado del `payload` de la `Alert`, mismo patrón que `ProgramTaskPayload`.
```php
final class HealthPlanMonthPayload extends Data
{
    public function __construct(public int $month) {}
}
```

#### `back/app/Notifications/Builders/HealthPlanMonthMessageBuilder.php`
**Propósito:** pieza central de RF-02. Recalcula actividades pendientes en destino y aborta (`null`) si no queda ninguna.
```php
final class HealthPlanMonthMessageBuilder implements AlertMessageBuilder
{
    private const MONTH_NAMES = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

    public function type(): AlertType
    {
        return AlertType::HealthPlanMonth;
    }

    public function build(Alert $alert, Recipient $recipient): ?MessageContent
    {
        /** @var EstablishmentHealthPlan $plan */
        $plan = $alert->subject;
        $payload = HealthPlanMonthPayload::from($alert->payload);

        // RF-02: se consulta el estado ACTUAL en DB, no un snapshot guardado en el payload.
        $pendingActivities = $plan->activities()
            ->where('month', $payload->month)
            ->whereNull('confirmed_at')
            ->with('activity')
            ->orderBy('sort_order')
            ->get();

        if ($pendingActivities->isEmpty()) {
            return null; // RF-02 AC#2 — todo confirmado entre creación y envío: no se envía
        }

        $monthName = self::MONTH_NAMES[$payload->month] ?? (string) $payload->month;
        $activitiesList = $pendingActivities->pluck('activity.name')->implode(', ');

        if ($recipient->channel === Channel::Email) {
            return new EmailContent(
                subject: "Recordatorio: plan sanitario de {$plan->establishment->name}",
                body: "Hola {$recipient->name}, en {$monthName} corresponde: {$activitiesList} "
                    . "({$plan->establishment->name}, {$plan->client->name}).",
            );
        }

        if ($recipient->channel === Channel::Push) {
            return new PushContent(
                title: 'Plan sanitario — actividades del mes',
                body: "{$plan->establishment->name}: {$activitiesList}",
                tag: "alert-{$alert->guid}",
                url: "establishment-health-plans/{$plan->guid}",
                data: [
                    'requires_confirmation' => false,
                    'alert_guid' => $alert->guid,
                    'establishment_health_plan_guid' => $plan->guid,
                ],
            );
        }

        return new TemplateContent(
            type: AlertType::HealthPlanMonth,
            variables: [
                '1' => $recipient->name,
                '2' => $monthName,
                '3' => $activitiesList,
                '4' => $plan->template->name,
                '5' => $plan->template->category->name,
                '6' => $plan->client->name,
                '7' => $plan->establishment->name,
            ],
        );
    }
}
```
Nota de performance: `$plan->activities()` (relación, no `whenLoaded`) fuerza una query fresca por invocación de `build()` — a propósito (RF-02), y acotado: un `DeliverAlertJob` procesa UN `AlertRecipient` por vez, así que es una query puntual, no un N+1 sobre una colección grande.

### Tests a generar

- `GenerateHealthPlanMonthAlertsListenerTest` (`tests/Unit/Notifications/`): una `Alert` por mes distinto con actividades; descarte de meses con `scheduled_at` pasado (RF-01 AC#3); sin alerta para meses sin actividades (AC#4); destinatarios = únicamente perfiles con rol `vet` del `vet_id` del plan (RF-03, regla dura #4 — incluir caso con `vet-assistant`/`client-owner` presentes en el tenant, verificar que NO reciben `AlertRecipient`); `payload` correcto (`{"month": N}`, sin actividades incluidas).
- `CancelHealthPlanMonthAlertsListenerTest`: cancela solo `pending`; no toca `Alert` ya `dispatched`/`sent` (RF-04 AC#2); no toca alertas de otro plan.
- `HealthPlanMonthMessageBuilderTest` (`tests/Unit/Notifications/`): con actividades pendientes arma `EmailContent`/`PushContent`/`TemplateContent` correctamente con el mes recalculado; con 0 pendientes (todas confirmadas después de crear la `Alert`) retorna `null`; con algunas confirmadas y otras no, la lista solo incluye las pendientes (RF-02 AC#3).
- `DeliverAlertJobTest` (extender el existente): builder que retorna `null` → `AlertRecipient.status = Suppressed`, `failure_reason = 'no_longer_applicable'`, gateway NUNCA se invoca, sin intento de fallback.
- `WhatsappTemplateCatalogTest` (extender): pin de `HealthPlanMonth` — `placeholderCount(body) === 7`, `exampleVariables()` devuelve 7 entradas.
- Actualizar `KapsoCreateTemplatesCommandTest` si asume un set cerrado de `AlertType`s en `WhatsappTemplateCatalog::definitions()`.
- `EstablishmentHealthPlanServiceTest` (extender el existente): `create()` dispara `EstablishmentHealthPlanInstantiatedEvent` (usar `Event::fake()`); `cancel()` dispara `EstablishmentHealthPlanCancelledEvent`.
- Feature end-to-end (`tests/Feature/`, nuevo o extendiendo `EstablishmentHealthPlanControllerTest`): instanciar un plan con `Event::fake()` deshabilitado (dejar correr el listener real) → assert que se crearon N `Alert` `HealthPlanMonth` con destinatarios correctos; confirmar todas las actividades de un mes antes de que corra `alerts:dispatch-due` → correr `DispatchDueAlerts` + procesar el `DeliverAlertJob` sincrónicamente (`Queue::fake()` + `assertPushed` o ejecución directa) → assert `Suppressed`.

## Cambios en FRONTEND

Sin cambios. Esta fase es 100% generación/envío de notificaciones en background — no expone endpoints nuevos ni modifica el contrato de los ya existentes de Fase 1 (confirmado en la spec funcional, "Roles y permisos": "esta feature no expone ningún endpoint nuevo").

## Orden de implementación

1. `back/app/Providers/AppServiceProvider.php` — agregar `establishment_health_plan` al `morphMap`.
2. `back/app/Notifications/Data/Payloads/HealthPlanMonthPayload.php`.
3. `back/app/Events/EstablishmentHealthPlanInstantiatedEvent.php` + `EstablishmentHealthPlanCancelledEvent.php`.
4. `UserProfileRepositoryInterface::listByRoleForVet` + implementación en `UserProfileRepositoryEloquent`.
5. `back/app/Listeners/GenerateHealthPlanMonthAlertsListener.php` + `CancelHealthPlanMonthAlertsListener.php`.
6. `back/app/Services/EstablishmentHealthPlanService.php` — dispatch de eventos en `create()`/`cancel()` + ampliar `load()`.
7. `AlertMessageBuilder::build()` → `?MessageContent` + `DeliverAlertJob` — chequeo de `null`.
8. `back/app/Notifications/Builders/HealthPlanMonthMessageBuilder.php` + registrar en `NotificationServiceProvider` (tag `alert.builders`).
9. `WhatsappTemplateCatalog` — entrada `HealthPlanMonth` + `config/notifications.php` (twilio/kapso templates + env vars) + `TwilioCreateTemplatesCommand::TEMPLATES`.
10. Tests backend (listeners, message builder, `DeliverAlertJob`, `WhatsappTemplateCatalog`, `EstablishmentHealthPlanService`, feature end-to-end) — correr `qa-backend`.
11. Deploy/ops (fuera de este plan de código, documentar en README o runbook del equipo): `TWILIO_TEMPLATE_HEALTH_PLAN_MONTH` / `KAPSO_TEMPLATE_HEALTH_PLAN_MONTH` en `.env` de cada ambiente, correr `php artisan twilio:create-templates` / `kapso:create-templates` y esperar aprobación de Meta antes de que el canal WhatsApp de esta alerta funcione en producción (el canal Push no depende de esto y funciona apenas se despliega el código).

## Riesgos y consideraciones

- **Cambio de contrato en `AlertMessageBuilder::build()` (`MessageContent` → `?MessageContent`).** Es retrocompatible a nivel de tipos (covariante), pero es la primera vez que el pipeline genérico admite "no enviar" desde un builder — cualquier desarrollo futuro que agregue un builder nuevo debe saber que `null` es un valor de retorno válido y con semántica propia (ver `DeliverAlertJob`). Vale la pena mencionarlo en el PR de esta feature para que quede en el review.
- **Timezone naive heredado (DEC2-05).** No es una regresión de esta feature — es el mismo comportamiento que ya tiene `ProgramTaskDue` hoy en producción — pero se vuelve más visible porque la spec funcional lo señaló explícitamente. Si el negocio decide resolverlo, debería hacerse para ambos tipos de alerta a la vez (`ProgramTaskDue` y `HealthPlanMonth`), no solo para el nuevo.
- **Plantilla de WhatsApp sin aprovisionar en el proveedor real.** El código deja todo listo (catálogo, config, comando de aprovisionamiento) pero el `contentSid`/aprobación de Meta es un paso operativo externo al repositorio. Hasta que se complete, cualquier envío de `HealthPlanMonth` por WhatsApp fallará con `TemplateNotConfiguredException` (manejado con gracia: se loguea, se marca `Failed`, cae a fallback `email` — el usuario igual recibe la notificación, solo que no por WhatsApp). Push no depende de este paso.
- **`EstablishmentHealthPlanCancelledEvent` reutiliza el nombre "Cancelled" pero NO crea una `Alert` de aviso de cancelación** (a diferencia de `ProgramCancelledEvent`, que sí notifica que el programa fue cancelado). Es una decisión correcta según DU2-04 (que solo pide borrar las pendientes, sin mencionar aviso de cancelación) pero puede sorprender a quien conozca el patrón de `Program` y espere el mismo doble efecto — documentado acá explícitamente para que no se "complete" por simetría sin que el negocio lo pida.
- **Dependencia de `$plan->client->country` no nulo.** Igual que en Fase 1 (`StoreEstablishmentHealthPlanRequest` ya valida esto al crear), si en algún momento un `Client` queda sin `country_id`, `HealthPlanYear::dateForMonth()` fallaría dentro del listener síncrono de creación — y al correr dentro de la misma transacción que `EstablishmentHealthPlanService::create()`, un error ahí revertiría la creación completa del plan (`Alert`s y `EstablishmentHealthPlanActivity` incluidos). Dado que Fase 1 ya valida `country` no nulo en el `FormRequest` antes de llegar al Service, este caso no debería ocurrir en la práctica — pero es una dependencia implícita entre fases que vale la pena que el dev tenga presente al escribir el test de "actividad materializada sin país" (no debería ser alcanzable vía API, pero si se llama al Service directamente en un test, hay que setear un país).
- **Catálogo global sin `country_id` (heredado de Fase 1, no de esta fase).** Sin impacto adicional aquí — ya estaba documentado como riesgo aceptado para el mercado inicial 100% AR.

## Pendientes / fuera de alcance

- Aprovisionamiento real del `contentSid`/aprobación de Meta para la plantilla `health_plan.month` — tarea operativa, no de código (ver "Orden de implementación", paso 11).
- Resolver el timezone naive heredado para `ProgramTaskDue` + `HealthPlanMonth` juntos — deuda técnica preexistente, no bloqueante.
- Recordatorios / segundo aviso — explícitamente fuera de alcance por DU2-05.
- Notificación a `vet-assistant`/`client-owner`/`client-manager` — explícitamente fuera de alcance por DU2-02/DU2-03 de la spec.
- Portal de autenticación para `client-owner`/`client-manager` — no aplica a esta fase (esos roles no son destinatarios de `HealthPlanMonth`), sigue pendiente solo para el flujo de confirmación de Fase 1 (ya documentado en el plan de Fase 1).
