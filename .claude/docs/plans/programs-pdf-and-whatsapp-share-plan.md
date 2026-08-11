# Plan técnico: PDF de Programa (descarga async) + Envío por WhatsApp

## Input procesado
Brief informal del usuario (texto libre), módulo Programs, tenant vet. Sin spec/ticket previo.

## Resumen ejecutivo
Se agregan dos capacidades sobre `Program`: (1) generar un PDF del programa (detalle + tareas agrupadas por `ProgramTarget`, con columna "notifica" y resaltado de tareas importantes) descargable de forma asíncrona con notificación in-app al terminar; (2) enviar ese mismo PDF por WhatsApp a destinatarios del lado cliente, elegidos en un modal, respetando la política de templates aprobados de WhatsApp Business. Ambas features reutilizan al máximo infraestructura ya existente: el módulo `Export` (Job/Events/Notification) para la descarga, y el módulo `Notifications` (Alert/AlertRecipient/DeliverAlertJob/gateways) para el envío. No se crean patrones nuevos de Job/cola — se extienden los dos pipelines existentes.

## Decisiones tomadas

DEC-01 — Reutilizar el módulo `Export` genérico para el PDF, en vez de un Job ad-hoc
  Decisión: agregar `ExportType::PROGRAM` (`'program'`), un `ProgramPdfExporter implements ExporterInterface`, y un caso nuevo en `ExportResolverService`. La descarga async pasa por `ExportService::initiate(..., async: true)` → `ProcessExportJob` → eventos `ExportStarted/Completed/FailedEvent` → `NotifyExportCompletedListener` (ya dispara notificación in-app con `url` de descarga).
  Justificación: el brief pedía "un Job igual en patrón a `ProcessExportJob`, mismos eventos, notificación in-app con link" — eso es literalmente lo que ya existe y funciona hoy para Users/Roles. Crear un segundo Job paralelo violaría DRY y divergería en mantenimiento (dos lugares para tries/timeout/eventos).
  Alternativa descartada: `ProcessProgramPdfJob` nuevo, standalone. Descartado por duplicar sin necesidad — el contrato de `ExporterInterface::export(filters, columns, filePath): string` ya admite pasar `filters['program_guid']` y generar cualquier PDF custom (ver DEC-02).

DEC-02 — `ProgramPdfExporter` NO extiende `BasePdfExporter`, implementa `ExporterInterface` directo
  Decisión: `BasePdfExporter::export()` es `final` y está diseñado para listados tabulares homogéneos (title + columns + rows). El PDF de programa es un documento de detalle con secciones (encabezado, datos del programa, N grupos con sub-tablas de tareas) — no encaja en ese contrato.
  Justificación: forzar el shape tabular de `BasePdfExporter` para un documento de detalle produciría una vista Blade contorsionada. Se sigue el mismo patrón bajo nivel que `UsersExporter` (implementación directa de `ExporterInterface`, usando `Pdf::loadView(...)->save()` explícito vía `Storage::disk('local')->put()`), pero con una vista Blade propia (`resources/views/exports/programs/detail.blade.php`).
  Alternativa descartada: extender `BasePdfExporter` sobreescribiendo `view()`. Descartado porque `export()` es `final` y no permite datos estructurados por grupo/tarea, solo `rows: string[][]`.

DEC-03 — Agrupación por `ProgramTarget`, orden cronológico real, columna "Notifica"
  Decisión: el PDF agrupa igual que `ProgramTargetsTimeline.vue` (un bloque por `ProgramTarget`, ordenado por `target_date`), y dentro de cada bloque lista las tareas del protocolo ordenadas por fecha real calculada (`DateOffset::apply(target_date, task.days_offset, task.time_of_day)`), no por `days_offset` crudo. Cada fila de tarea tiene una columna booleana "Notifica" = `task.alerts->isNotEmpty()`. Las tareas con `important = true` se resaltan (fondo/borde distinto), sin mostrar destinatarios/alertas en detalle.
  Justificación: reutiliza el cálculo ya existente y probado en `ProgramService::projectTargetTasks` (privado) y en `GenerateProgramTaskDueAlertsListener` — mismo cálculo de fecha en tres lugares (preview, generación real de alertas, PDF) sería una fuente de bugs de sincronización si se reimplementa.
  Alternativa descartada: recalcular fechas dentro del exporter. Descartado — debe reusarse `DateOffset::apply` a través de un método explícito, no un tercer cálculo paralelo.

DEC-04 — Nuevo método público en `ProgramService` para alimentar el PDF, distinto de `projectTargetTasks`
  Decisión: agregar `ProgramService::projectTasksForPdf(Program $program): array` — misma lógica de fecha que `projectTargetTasks`, pero SIN el bloque de `recipients` (el brief pide expresamente no listar destinatarios/alertas, solo el booleano de si notifica). No se reutiliza `projectTargetTasks` directamente porque ese método muta `$target->projected_tasks` (side effect pensado para el Resource del detalle) y expone `recipients`, que el PDF no debe mostrar.
  Justificación: mantener el principio de "un solo cálculo de fecha, dos proyecciones de datos según consumidor" sin filtrar exceso de información hacia el PDF.
  Alternativa descartada: reusar `projectTargetTasks` y ocultar `recipients` en la vista Blade. Descartado — el dato sensible viajaría igual hasta la capa de presentación, violando el principio de mínima exposición de datos.

DEC-05 — Filtro de "destinatarios del lado cliente": `UserProfileService::CLIENT_STAFF_ROLES`
  Decisión: el modal de envío lista `program.managers` filtrados por `in_array($manager->role->name, UserProfileService::CLIENT_STAFF_ROLES, true)`.
  Justificación: NO es una inferencia — es la fuente de verdad ya usada en `ProgramResource::toArray()` (línea `'origin' => in_array($m->role->name, UserProfileService::VET_STAFF_ROLES, true) ? 'vet' : 'client'`) y documentada en el frontend (`ProgramManagerRef.origin`, comentario "DEC-16"). `CLIENT_STAFF_ROLES = ['client-owner', 'client-manager', 'client-administrative']`. Esto **resuelve el punto de exploración pedido por el usuario** — no hay bloqueo ni ambigüedad en el modelo de datos.
  Alternativa descartada: inferir por prefijo `'client-%'` en el nombre del rol. Descartado por existir ya una constante explícita mantenida a propósito para este fin.

DEC-06 — Envío de WhatsApp reutiliza el pipeline `Alert`/`AlertRecipient`/`DeliverAlertJob`, con despacho inmediato (bypass del cron)
  Decisión: al confirmar el modal, se crea un `Alert` (`type = AlertType::ProgramPdfShared` nuevo, `subject = program`, `scheduled_at = now()`, `status = 'pending'`, `payload = ['export_guid' => ..., 'pdf_share_url' => ...]`) + un `AlertRecipient` por destinatario seleccionado (`channel = Channel::Whatsapp`, `status = Pending`). Inmediatamente después, en la misma transacción/servicio, se despacha `DeliverAlertJob::dispatch($recipient->id)` por cada recipient creado (sin esperar el comando `alerts:dispatch-due`, que solo corre por cron para alertas *futuras*).
  Justificación: reutiliza integralmente idempotencia, reintentos con backoff, `ChannelFallbackService`, registro de `DeliveryStatus` y el webhook de status de Twilio — toda infraestructura ya construida y probada para WhatsApp. Construir un envío "de una sola vez" fuera de este pipeline duplicaría lógica de resolución de contacto y manejo de fallos.
  Alternativa descartada: llamar al gateway directo desde el controller/service sin pasar por `Alert`/`AlertRecipient`. Descartado — se perdería trazabilidad de entrega (no quedaría registro de qué se envió, a quién, ni su estado), y el requerimiento de "elegir destinatarios y ver cuáles no tienen WhatsApp" calza naturalmente con el modelo de contactos ya usado por `AlertRecipient::toDto()`.

DEC-07 — Nuevo `AlertMessageBuilder` + nuevo `AlertType::ProgramPdfShared` + nuevo template de WhatsApp tipo *media*
  Decisión: agregar `AlertType::ProgramPdfShared = 'program.pdf_shared'`; entrada nueva en `WhatsappTemplateCatalog::definitions()` con body de texto (ej. `'Hola {{1}}, te compartimos el PDF del programa "{{2}}".'`) más una variable adicional reservada para la URL del documento (`{{3}}`); `ProgramPdfShareMessageBuilder implements AlertMessageBuilder` que construye `TemplateContent(type: AlertType::ProgramPdfShared, variables: [1 => name, 2 => program.protocol.name, 3 => $pdfShareUrl])`.
  Justificación (política de WhatsApp, no opinión — confirmada por el usuario en el brief): todo mensaje iniciado por el negocio fuera de la ventana de 24hs requiere SIEMPRE un template aprobado por Meta; para adjuntar un documento, ese template debe crearse en el Content API de Twilio como tipo `twilio/media` (o `twilio/document`), con un header de medio cuya URL se pasa como una de las `contentVariables` posicionales al momento del envío — exactamente el mismo mecanismo que ya usa `TwilioWhatsappGateway::send()` para `TemplateContent` (contentSid + contentVariables). **No hace falta modificar `TwilioWhatsappGateway` ni `TemplateContent`** — el mecanismo existente ya soporta pasar una URL como variable ordinal, siempre que el template en Twilio Content Composer esté configurado con un placeholder de media que consuma esa variable.
  Riesgo/pendiente marcado explícitamente: la creación real del template en Twilio Content Composer (tipo media/document) y la obtención de su `contentSid` es un paso operativo fuera del código — debe hacerlo quien tenga acceso a la consola de Twilio, y el `contentSid` resultante se configura en `config/notifications.php` (o el archivo de config de gateways existente) bajo la key `AlertType::ProgramPdfShared->value`. Este plan no puede verificar en el entorno si Twilio realmente acepta la URL como variable posicional para el header de medios sin probarlo contra la cuenta real — **recomendado**: validar con un envío de prueba antes de dar por cerrado el punto 4 del brief.
  Alternativa descartada: extender `TemplateContent`/`OutboundMessage` con un campo `mediaUrl` explícito y pasar `MediaUrl` a `$this->twilio->messages->create()`. Descartado como default porque el envío de `MediaUrl` fuera de una `contentSid` de template solo es válido dentro de la ventana de sesión de 24hs (mensaje de servicio), no para mensajes iniciados por el negocio — usarlo violaría la regla de negocio confirmada. Se deja documentado como plan B si Twilio rechazara variables de media vía `contentVariables` en la prueba real.

DEC-08 — URL pública firmada y temporal para que Twilio pueda descargar el PDF
  Decisión: nueva ruta pública (sin `auth:sanctum`) `GET /v1/programs/{export_guid}/shared-pdf` protegida con `Illuminate\Routing\Middleware\ValidateSignature` (firma de Laravel, `URL::temporarySignedRoute`, expiración configurable — ej. 24hs), que sirve el archivo del `Export` asociado si `isDownloadable()`.
  Justificación: el endpoint de descarga actual (`/v1/exports/{guid}/download`) exige `auth:sanctum` — Twilio no puede autenticar como usuario de la app. WhatsApp Business exige que la URL del media sea públicamente alcanzable por HTTPS sin credenciales. Una URL firmada con expiración acota el riesgo de exposición (regla dura #4 de multi-tenant no aplica a Twilio como consumidor externo, pero la firma+expiración es el control equivalente).
  Alternativa descartada: exponer el archivo sin firma ni expiración. Descartado por riesgo de seguridad — cualquiera con el link tendría acceso indefinido al PDF del programa de un cliente.

DEC-09 — El "Enviar" WhatsApp reusa el mismo `Export` (PDF) que "Descargar", generado de forma síncrona si no existe uno vigente
  Decisión: al abrir el modal de envío, el backend verifica si ya existe un `Export` tipo `program`/`pdf` no expirado para ese `program_guid` (creado por el usuario actual); si no, lo genera de forma síncrona (`ExportService::initiate(..., async: false)`) antes de listar destinatarios — el modal no debe bloquear esperando un Job async.
  Justificación: evita generar un PDF nuevo cada vez que se envía, y evita que "Enviar" dependa de haber apretado "Descargar" antes. Generar el PDF de un programa es una operación liviana (un query + un `Pdf::loadView`), aceptable en el ciclo síncrono de un request.
  Alternativa descartada: obligar al usuario a descargar primero. Descartado — el brief no lo pide y generaría una dependencia de UX innecesaria entre las dos acciones del dropdown.

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Exports/Programs/ProgramPdfExporter.php`
**Propósito:** genera el PDF de detalle de un programa (encabezado + datos + tareas agrupadas por target).
**Firma principal:**
```php
final class ProgramPdfExporter implements ExporterInterface
{
    public function __construct(
        private readonly ProgramRepositoryInterface $programRepository,
        private readonly ProgramService $programService,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        // $filters['program_guid'] y $filters['vet_id'] son obligatorios (scope tenant, regla dura #4)
        $program = $this->programRepository->findByGuidForVet($filters['program_guid'], $filters['vet_id']);
        if ($program === null) {
            throw new \RuntimeException("Programa {$filters['program_guid']} no encontrado para este vet.");
        }
        $program->loadMissing('client', 'establishment', 'technique', 'protocol', 'targets.animals');
        $groups = $this->programService->projectTasksForPdf($program);

        $pdf = Pdf::loadView('exports.programs.detail', [
            'program' => $program,
            'groups'  => $groups, // array<{ target: ProgramTarget, tasks: array }>
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($filePath, $pdf->output());
        return $filePath;
    }

    public function getExtension(): string { return 'pdf'; }
    public function getMimeType(): string { return 'application/pdf'; }
}
```
**Dependencias inyectadas:** `ProgramRepositoryInterface`, `ProgramService`.

#### `back/resources/views/exports/programs/detail.blade.php`
**Propósito:** vista Blade del PDF. Encabezado con logo SAV (`public/images/logo-sav.png` o el asset ya usado en `exports.generic`, verificar), datos del programa (cliente/establecimiento/técnica/protocolo/estado/comentarios), y un bloque por cada grupo (`ProgramTarget`): fecha del grupo + animales, tabla de tareas (columnas: fecha real, descripción, notifica sí/no) con fila resaltada (`class="task-important"`) cuando `task.important`.

#### `back/app/Notifications/Builders/ProgramPdfShareMessageBuilder.php`
**Propósito:** construye el `TemplateContent` de WhatsApp para el envío de PDF.
**Firma principal:**
```php
final class ProgramPdfShareMessageBuilder implements AlertMessageBuilder
{
    public function type(): AlertType { return AlertType::ProgramPdfShared; }

    public function build(Alert $alert, Recipient $recipient): MessageContent
    {
        /** @var Program $program */
        $program = $alert->subject;

        return new TemplateContent(
            type: AlertType::ProgramPdfShared,
            variables: [
                1 => $recipient->name,
                2 => $program->protocol->name,
                3 => $alert->payload['pdf_share_url'],
            ],
        );
    }
}
```
(Registrado automáticamente en `MessageBuilderRegistry` si el binding usa tag/discovery igual que los builders existentes — verificar `NotificationServiceProvider` al implementar.)

#### `back/app/Http/Controllers/V1/ProgramPdfController.php`
**Propósito:** endpoints de descarga async y de envío por WhatsApp del PDF de un programa.
**Firma principal:**
```php
class ProgramPdfController extends Controller
{
    public function __construct(
        private ProgramService $programService,
        private ExportService $exportService,
        private ProgramShareService $programShareService,
    ) {}

    // POST /v1/vets/{vet}/programs/{guid}/pdf  -> inicia Export async, dispara notificación al terminar (vía listener existente)
    public function requestPdf(Request $request, string $guid): JsonResponse { /* ... */ }

    // GET /v1/vets/{vet}/programs/{guid}/share-recipients -> lista destinatarios lado cliente + flag has_whatsapp
    public function shareRecipients(Request $request, string $guid): JsonResponse { /* ... */ }

    // POST /v1/vets/{vet}/programs/{guid}/share -> dispara envío WhatsApp a manager_profile_ids seleccionados
    public function share(ShareProgramPdfRequest $request, string $guid): JsonResponse { /* ... */ }
}
```

#### `back/app/Services/ProgramShareService.php`
**Propósito:** orquesta "obtener/generar Export PDF vigente" + "listar destinatarios lado cliente con flag de WhatsApp" + "crear Alert/AlertRecipient y despachar DeliverAlertJob".
**Firma principal:**
```php
class ProgramShareService
{
    public function __construct(
        private ExportService $exportService,
        private UserProfileService $userProfileService, // fuente de CLIENT_STAFF_ROLES
    ) {}

    public function listClientRecipients(Program $program): array
    {
        // return [{ profile_guid, name, role, has_whatsapp: bool }]
    }

    /** Genera (o reusa) el Export PDF síncrono para compartir por WhatsApp. */
    public function getOrCreateShareableExport(Program $program, User $user): Export { /* ... */ }

    /**
     * @param string[] $managerProfileGuids
     */
    public function sendPdfToRecipients(Program $program, Export $export, array $managerProfileGuids, int $vetId): Alert
    {
        // 1. resuelve managerProfileGuids -> UserProfile (validando que sean CLIENT_STAFF_ROLES y pertenezcan al program)
        // 2. crea signed URL temporal (route('programs.shared-pdf', signed) hacia $export)
        // 3. crea Alert(type=ProgramPdfShared, subject=program, scheduled_at=now(), status=pending, payload=[...])
        // 4. crea un AlertRecipient(channel=Whatsapp, status=Pending) por cada profile válido
        // 5. marca $alert->status = 'dispatched' y despacha DeliverAlertJob::dispatch($recipient->id) por cada uno
    }
}
```

#### `back/app/Http/Requests/Programs/ShareProgramPdfRequest.php`
**Propósito:** valida `manager_profile_ids: string[]` (guids), `min:1`, cada uno debe existir y pertenecer a `program.managers` (validación en `withValidator`, mismo patrón que `StoreProgramRequest`).

#### `back/app/Http/Resources/V1/ProgramShareRecipientResource.php`
**Propósito:** shape `{ guid, name, role, has_whatsapp }` para el listado del modal.

### Archivos a modificar

#### `back/app/Enums/ExportType.php`
**Cambio:** agregar `case PROGRAM = 'program';` + label `'Programa (PDF)'`.

#### `back/app/Services/Exports/ExportResolverService.php`
**Cambio:** agregar rama `$typeEnum === ExportType::PROGRAM && $formatEnum === ExportFormat::PDF => new ProgramPdfExporter(...)`. Inyectar `ProgramRepositoryInterface` y `ProgramService` en el constructor.

#### `back/app/Services/ProgramService.php`
**Cambio:** agregar método público nuevo (ver DEC-04):
```php
public function projectTasksForPdf(Program $program): array
{
    $program->loadMissing('protocol.tasks.alerts', 'targets.animals');
    return $program->targets->map(function (ProgramTarget $target) use ($program) {
        $tasks = $program->protocol->tasks->map(function ($task) use ($target) {
            return [
                'description' => $task->description,
                'occurs_on'   => $this->applyOffset($target->target_date, $task->days_offset, $task->time_of_day)->toDateString(),
                'occurs_at'   => $task->time,
                'important'   => $task->important,
                'notifies'    => $task->alerts->isNotEmpty(),
            ];
        })->sortBy('occurs_on')->values()->all();
        return ['target' => $target, 'tasks' => $tasks];
    })->all();
}
```

#### `back/app/Notifications/Enums/AlertType.php`
**Cambio:** agregar `case ProgramPdfShared = 'program.pdf_shared';`.

#### `back/app/Notifications/Templates/WhatsappTemplateCatalog.php`
**Cambio:** agregar entrada `AlertType::ProgramPdfShared->value => ['body' => 'Hola {{1}}, te compartimos el PDF del programa "{{2}}".', 'examples' => ['Lucas', 'Sincronización IATF']]`. La variable `{{3}}` (URL del media) no forma parte del `body` de texto — corresponde al header de medio del template en Twilio, fuera del copy textual; documentarlo en comentario dentro del catálogo.

#### `back/routes/api/programs.php`
**Cambio:** agregar dentro del grupo tenant existente:
```php
Route::post('/{guid}/pdf', [ProgramPdfController::class, 'requestPdf'])->middleware('can:programs.read');
Route::get('/{guid}/share-recipients', [ProgramPdfController::class, 'shareRecipients'])->middleware('can:programs.read');
Route::post('/{guid}/share', [ProgramPdfController::class, 'share'])->middleware('can:programs.read');
```
Y una ruta pública nueva (fuera del grupo `auth:sanctum`, en su propio archivo o al final de `programs.php` con middleware distinto):
```php
Route::get('/v1/programs/shared-pdf/{export}', [ProgramPdfController::class, 'servePublicPdf'])
    ->middleware('signed')
    ->name('programs.shared-pdf');
```

#### `back/database/seeders/ProgramPermissionsSeeder.php`
**Cambio:** ninguno nuevo requerido — se reutiliza `programs.read` para las tres rutas nuevas (descargar/listar destinatarios/enviar son todas operaciones de lectura+notificación sobre un programa existente, no alteran el programa). Documentado como decisión, no como permiso nuevo.

### Migrations
Ninguna. `Export`, `Alert`, `AlertRecipient` ya tienen todas las columnas necesarias. `ExportType`/`AlertType` son enums PHP nativos (no columnas ENUM de DB) — agregar un `case` no requiere migración.

### Rutas API
| Método | Path | Controller@action | Middleware | Permiso |
|---|---|---|---|---|
| POST | `v1/vets/{vet}/programs/{guid}/pdf` | `ProgramPdfController@requestPdf` | `auth:sanctum`, `vet.tenant` | `programs.read` |
| GET | `v1/vets/{vet}/programs/{guid}/share-recipients` | `ProgramPdfController@shareRecipients` | `auth:sanctum`, `vet.tenant` | `programs.read` |
| POST | `v1/vets/{vet}/programs/{guid}/share` | `ProgramPdfController@share` | `auth:sanctum`, `vet.tenant` | `programs.read` |
| GET | `v1/programs/shared-pdf/{export}` (named `programs.shared-pdf`) | `ProgramPdfController@servePublicPdf` | `signed` (sin `auth:sanctum`) | — |

### Contrato de los endpoints

**POST `.../programs/{guid}/pdf`**
Request: sin body.
Response 202: `{ success: true, data: ExportResource, message: "Generación de PDF iniciada." }` (mismo shape que `POST /v1/exports`, `async: true`).
Flujo: internamente llama `ExportService::initiate(user, 'program', 'pdf', ['program_guid' => guid, 'vet_id' => vet.id], [], async: true)`. Al completarse, `NotifyExportCompletedListener` ya dispara la notificación in-app con `url: /exports/{guid}/download` (reusar tal cual, sin cambios).

**GET `.../programs/{guid}/share-recipients`**
Response 200: `{ success: true, data: [{ guid, name, role, has_whatsapp }] }` — vía `ProgramShareRecipientResource`.

**POST `.../programs/{guid}/share`**
Request: `{ manager_profile_ids: string[] }` (guids de `UserProfile`, deben tener `has_whatsapp: true`, validado server-side igual — el front deshabilita pero el backend es la fuente de verdad).
Response 200: `{ success: true, data: { alert_guid, recipients_count }, message: "Envío iniciado." }`.
Errores posibles: 422 si algún guid no pertenece a `program.managers` o no es `CLIENT_STAFF_ROLES`; 422 si algún guid seleccionado no tiene contacto WhatsApp habilitado (backend re-valida, no confía en el front).

**GET `v1/programs/shared-pdf/{export}`**
Response 200: binario PDF (`Content-Type: application/pdf`), sin sobre `{success,data}` (igual que `ExportController::download`).
Errores: 403 si la firma es inválida/expiró (manejado por middleware `signed` de Laravel); 404/422 si el `Export` no es `isDownloadable()`.

### Tests a generar
- `ProgramPdfExporterTest`: genera PDF para un programa con 2 targets y verifica que el archivo se crea en el path esperado, sin fallar si el protocolo no tiene tareas.
- `ProgramServiceTest::test_projectTasksForPdf_ordena_por_fecha_real_no_por_offset` — cubrir un caso donde `days_offset` de una tarea sea mayor pero su fecha resultante quede antes por combinación de `time_of_day`/target distinto.
- `ProgramServiceTest::test_projectTasksForPdf_notifies_false_si_sin_alerts`.
- `ProgramShareServiceTest::test_listClientRecipients_excluye_roles_vet` — verificar que solo `CLIENT_STAFF_ROLES` aparecen.
- `ProgramShareServiceTest::test_listClientRecipients_has_whatsapp_false_sin_contacto_primario`.
- `ProgramShareServiceTest::test_sendPdfToRecipients_crea_alert_y_recipients_y_despacha_job` (usar `Bus::fake()` para verificar `DeliverAlertJob` despachado por cada recipient).
- `ProgramPdfControllerTest::test_share_rechaza_guid_que_no_pertenece_al_programa` (422).
- `WhatsappTemplateCatalogTest`: agregar caso `ProgramPdfShared` al test existente que pinea cada entrada contra su builder (mencionado en el comentario del catálogo).
- Feature test de la ruta pública firmada: 200 con firma válida, 403 con firma inválida/expirada.

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/programs/api/program-pdf.api.ts`
**Propósito:** llamadas HTTP nuevas.
```ts
export async function requestProgramPdfApi(vetGuid: string, guid: string): Promise<ExportItem>
export async function getShareRecipientsApi(vetGuid: string, guid: string): Promise<ProgramShareRecipient[]>
export async function shareProgramPdfApi(vetGuid: string, guid: string, payload: { manager_profile_ids: string[] }): Promise<{ alert_guid: string; recipients_count: number }>
```

#### `front/src/modules/programs/types/program-pdf.types.ts`
```ts
export interface ProgramShareRecipient {
  guid: string
  name: string
  role: string
  has_whatsapp: boolean
}
```

#### `front/src/modules/programs/composables/useRequestProgramPdf.ts`
**Propósito:** `useMutation` que llama `requestProgramPdfApi`; on success muestra `info('Generación de PDF iniciada. Te notificaremos cuando esté lista.')` — no hace descarga inmediata (siempre async, a diferencia de `useInitiateExport` que puede resolver sync). Invalida `['exports']` si se muestra en algún listado.

#### `front/src/modules/programs/composables/useProgramShare.ts`
**Propósito:** agrupa `useQuery(['program-share-recipients', guid])` (llama `getShareRecipientsApi`, habilitado solo cuando el modal está abierto) + `useMutation` para `shareProgramPdfApi` (on success cierra modal, `success('Envío iniciado.')`, invalida query de recipients).

#### `front/src/modules/programs/components/tenant/ProgramShareModal.vue`
**Propósito:** modal con checklist de destinatarios. Usa `BaseModal`. Cada fila: `a-checkbox` + nombre + `RoleChip` (componente ya usado en `ProgramManagerCheckboxGroup.vue`, verificar y reutilizar) + tag deshabilitado "Sin WhatsApp" cuando `has_whatsapp === false`. Checkbox `:disabled="!recipient.has_whatsapp"`.
Props: `programGuid: string`, `open: boolean` (v-model).
Emits: `update:open`, `sent`.

### Archivos a modificar

#### `front/src/modules/programs/components/tenant/ProgramsTable.vue`
**Cambio:** en la columna `actions`, agregar (dentro de `BaseTableActions`, junto a los `BaseButton` existentes) un `a-dropdown` con `overlay` tipo `a-menu` agrupado bajo el título "Programa":
```vue
<a-dropdown>
  <BaseButton variant="row-action" size="small" tooltip="Más acciones">
    <template #icon><MoreOutlined /></template>
  </BaseButton>
  <template #overlay>
    <a-menu>
      <a-menu-item-group title="Programa">
        <a-menu-item key="download" @click="onDownloadPdf(record as ProgramListItem)">
          Descargar
        </a-menu-item>
        <a-menu-item key="send" @click="onOpenShareModal(record as ProgramListItem)">
          Enviar
        </a-menu-item>
      </a-menu-item-group>
    </a-menu>
  </template>
</a-dropdown>
```
Se agrega `emit('download-pdf', program)` y `emit('open-share', program)`, siguiendo el patrón ya usado por `emit('cancel', ...)` en este mismo componente (las acciones que requieren estado/lógica se emiten hacia el padre, no se resuelven inline).
**Antes:** solo `EditOutlined`/`EyeOutlined`/`StopOutlined`.
**Después:** se agrega `MoreOutlined` + dropdown con 2 acciones nuevas.

#### `front/src/modules/programs/pages/tenant/VetProgramsListPage.vue`
**Cambio:** escuchar `@download-pdf` (llama `useRequestProgramPdf().mutate({ guid })`) y `@open-share` (setea `selectedProgramGuid` + `shareModalOpen = true`, monta `<ProgramShareModal :program-guid="selectedProgramGuid" v-model:open="shareModalOpen" />`).

### Rutas
Sin cambios — todo ocurre en la misma página de listado, sin navegación nueva.

### i18n
Agregar claves nuevas en `front/src/i18n/locales/es/programs.json` (o el archivo correspondiente del módulo): `programs.actions.group`, `programs.actions.download`, `programs.actions.send`, `programs.share.title`, `programs.share.noWhatsapp`, `programs.share.confirm`, `programs.share.success`.

### Tests a generar
- `ProgramsTable.spec.ts`: click en "Descargar" emite `download-pdf` con el `program` correcto; click en "Enviar" emite `open-share`.
- `ProgramShareModal.spec.ts`: destinatarios sin `has_whatsapp` renderizan checkbox `disabled`; confirmar con selección vacía deshabilita el botón de enviar; confirmar con selección válida llama la mutation con los guids correctos.
- `useProgramShare.spec.ts`: mockear API, verificar invalidación de query tras envío exitoso.

## Orden de implementación

1. Backend — `ExportType::PROGRAM`, `ProgramService::projectTasksForPdf()`, `ProgramPdfExporter`, vista Blade, wiring en `ExportResolverService`. Verificar manualmente generando un PDF de un programa de prueba (Tinker o test) antes de seguir.
2. Backend — endpoint `POST .../programs/{guid}/pdf` en `ProgramPdfController` (usa `ExportService::initiate` directamente, sin lógica nueva de negocio). Probar el flujo completo descarga async + notificación in-app reusando la infraestructura de `Export` intacta.
3. Backend — `AlertType::ProgramPdfShared`, entrada en `WhatsappTemplateCatalog`, `ProgramPdfShareMessageBuilder`, registro en `MessageBuilderRegistry` (verificar mecanismo de discovery en `NotificationServiceProvider`).
4. Backend — ruta pública firmada `programs.shared-pdf` + acción `servePublicPdf` en el controller.
5. Backend — `ProgramShareService` (listClientRecipients, getOrCreateShareableExport, sendPdfToRecipients) + `ShareProgramPdfRequest` + `ProgramShareRecipientResource` + endpoints `share-recipients`/`share`.
6. Backend — tests de todo lo anterior (ver sección de tests). Correr suite completa del módulo Programs y Notifications antes de pasar a frontend.
7. **Punto de coordinación externa, no bloqueante para el código**: crear el template `twilio/media` en Twilio Content Composer con el copy de `WhatsappTemplateCatalog::for(AlertType::ProgramPdfShared)`, obtener `contentSid`, configurarlo en el env/config de gateways. Hacer un envío de prueba real contra un número de WhatsApp Business Sandbox para confirmar que Twilio acepta la URL del PDF como variable posicional del header de medio (ver DEC-07 riesgo).
8. Frontend — `program-pdf.api.ts`, tipos, `useRequestProgramPdf`, `useProgramShare`.
9. Frontend — `ProgramShareModal.vue`.
10. Frontend — dropdown en `ProgramsTable.vue` + wiring en `VetProgramsListPage.vue`.
11. Frontend — tests + i18n.
12. QA manual end-to-end: descargar PDF de un programa con varios grupos/tareas importantes, confirmar visual; enviar por WhatsApp a un destinatario real y confirmar recepción del documento.

## Riesgos y consideraciones

- **Riesgo real, no teórico**: el mecanismo de "URL como variable posicional del header de medio" en Twilio Content API (DEC-07) es una inferencia basada en cómo Twilio expone `contentVariables` para templates de texto — el comportamiento exacto para templates `twilio/media` (si la variable de medio se llama `{{1}}` fijo, o si necesita una key nombrada distinta como `media`) **no está confirmado contra la cuenta real de Twilio del proyecto**. Marcado como paso 7 explícito, no bloqueante para escribir el código, pero sí bloqueante para el `go-live` de la feature de envío.
- **Multi-tenant (regla dura #4)**: `ProgramPdfExporter::export()` debe recibir `vet_id` en `$filters` y usar `ProgramRepositoryInterface::findByGuidForVet()` (no un `findByGuid` sin scope) — de lo contrario un vet podría generar el PDF de un programa de otro vet conociendo su guid.
- **GUID (regla dura #6)**: la ruta pública firmada usa el `guid` de `Export` en el path (`{export}` con route model binding por `guid`, verificar `getRouteKeyName()` de `Export` — ya usa `HasGuid`), no el `id` interno.
- **Permisos**: se decidió no crear permisos nuevos (DEC — reutilizar `programs.read`). Si en el futuro se quiere restringir "quién puede enviar por WhatsApp" de forma más granular que "quién puede ver el programa", habrá que introducir `programs.share` — fuera de alcance de esta iteración, documentado como pendiente.
- **Costo de Twilio**: cada envío de template con media tiene costo por mensaje (conversación de negocio). No hay límite de destinatarios en el modal — considerar agregar un tope razonable (ej. 20) si en producción se ve abuso, fuera de alcance ahora.
- **Idempotencia doble clic**: el botón "Enviar" del modal debe deshabilitarse mientras la mutation está `isPending` para evitar crear múltiples `Alert`/`AlertRecipient` duplicados por doble click — a implementar en `ProgramShareModal.vue`.
- **Expiración de Export**: `Export::expires_at` default es `now()->addDays(7)` (ver `ExportService::initiate`) — si el usuario reintenta "Enviar" después de 7 días, `getOrCreateShareableExport` debe detectar `isExpired()` y regenerar, no reusar un `Export` vencido.

## Pendientes / fuera de alcance
- Permiso granular `programs.share` diferenciado de `programs.read`.
- Límite de destinatarios por envío / rate limiting de WhatsApp.
- Reintento manual desde el frontend si un `AlertRecipient` queda en `Failed` (hoy solo hay reintento automático vía `ChannelFallbackService`/backoff del Job).
- Historial de envíos de PDF por programa en la UI (quién envió, cuándo, a quién) — los datos ya quedan persistidos en `Alert`/`AlertRecipient`, pero no hay pantalla que los liste; se puede armar como iteración futura reusando `AlertType::ProgramPdfShared`.
