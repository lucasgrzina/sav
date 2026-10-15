# Plan técnico: Dashboard admin con estadísticas reales (8 métricas)

## Input procesado
Brief informal del usuario + ajuste de alcance del coordinador: SOLO dashboard admin, métricas A1-A8. Exploración con Glob/Grep/Read (el MCP `codegraph_explore` no estaba disponible).

## Resumen ejecutivo
Hoy NO existe backend de dashboard y el FE admin (`front/src/modules/dashboard/`) es 100% mock (`dashboard.api.ts` con datos hardcodeados). Se crea un endpoint de solo lectura `GET /v1/admin/dashboard` que devuelve las 8 métricas globales en una sola respuesta, con `DashboardStatsService` + repository de lectura y cache de 5 min. El FE reescribe el módulo `dashboard` reutilizando `MetricCard`/`TrendChart` (SVG, sin dependencia nueva).

## Estado actual verificado
- BE: sin controller/service/ruta de dashboard. Las rutas se autocargan desde `back/routes/api/*.php`.
- Modelos útiles: `Vet` (`validated_at`, `suspended_at`), `Client`, `Establishment`, `Animal` (`type`), `Program` (`cancelled_at`), `ProgramTarget` (`target_date`), `App\Notifications\Models\AlertRecipient` (`channel`, `status` pending|sent|delivered|read|failed|suppressed, `sent_at`).
- Patrón de permisos: migración idempotente que inserta permiso y lo asigna a roles (ver `2026_10_13_000003_add_programs_alerts_read_permission.php`).
- FE: `TrendChart.vue` (barras SVG), `MetricCard.vue`, `AsyncContent.vue`, `AppMenu.vue` (item Dashboard con `permission: null`). Sin librería de charts en `package.json`.

## Métricas (scope global, solo plataforma)
Ventanas en `config('app.timezone')` (UTC, ver commit 58dce02).

| # | Métrica | Fuente / lógica | Viz |
|---|---|---|---|
| A1 | Empresas activas | `vets` validated_at not null y suspended_at null; meta: total y suspendidas | KPI |
| A2 | Empresas pendientes de validación | `vets` validated_at null y suspended_at null | KPI (destacado si >0) |
| A3 | Clientes | `clients` count | KPI |
| A4 | Establecimientos | `establishments` count | KPI |
| A5 | Animales (livestock) | `animals` type=livestock | KPI |
| A6 | Programas activos | `programs` cancelled_at null con algún `program_targets.target_date >= hoy` | KPI |
| A7 | Avisos enviados, últimos 7 días | `alert_recipients.sent_at` por día, status sent/delivered/read | Barras (`TrendChart`) |
| A8 | Tasa de entrega 30d por canal | (delivered+read)/(sent+delivered+read+failed) sobre `alert_recipients` 30d, excluye pending/suppressed | % + barra por canal |

## Decisiones tomadas

DEC-01 — Un endpoint agregado
  Decisión: `GET /v1/admin/dashboard` devuelve las 8 métricas.
  Justificación: una request, un cache key, un solo loading/error en FE.
  Alternativa descartada: un endpoint por métrica (más superficie y requests).

DEC-02 — Permiso
  Decisión: `dashboard.admin.read`, asignado SOLO a `super-admin` y `admin` (decisión cerrada: `operador` NO lo recibe) vía migración idempotente + `PermissionSeeder`/`RoleSeeder`. Rutas bajo `auth:sanctum` + `can:dashboard.admin.read`.
  Justificación: no reusar `vets.read` (semántica incorrecta); los roles tenant no tienen acceso.

DEC-03 — Cache
  Decisión (cerrada): `Cache::remember('dashboard:admin', 300, ...)` en el store configurado (`database`) para todo el payload EXCEPTO A2 (empresas pendientes de validación), que se consulta en vivo en cada request. Sin invalidación por eventos ni `?refresh`.
  Justificación: simple; los conteos no son críticos al segundo, pero A2 es accionable por el admin y debe reflejar validaciones al instante.

DEC-04 — Tasa de entrega y animales
  Decisión: denominador = sent+delivered+read+failed; animales filtran `type='livestock'`.
  Justificación: refleja intentos reales del pipeline y el único uso actual de `animals`.

DEC-05 — Gráficos sin dependencia nueva
  Decisión: `TrendChart.vue` + `a-progress` de Ant Design Vue.

## Cambios en BACKEND

### Archivos a crear
- `back/app/Contracts/Repositories/DashboardStatsRepositoryInterface.php` (verificar naming real en `Contracts/Repositories`; gana el código, ej. `VetRepositoryInterface`):
```php
interface DashboardStatsRepositoryInterface {
    public function vetCounts(): array;                    // active, suspended, total (cacheable)
    public function pendingVets(): int;                    // A2, consulta en vivo (sin cache)
    public function platformTotals(CarbonInterface $today): array; // clients, establishments, animals, active_programs
    public function sentPerDay(CarbonInterface $from, CarbonInterface $to): array; // [Y-m-d => n]
    public function deliveryByChannel(CarbonInterface $from): array;               // [channel => [ok, failed]]
}
```
- `back/app/Repositories/DashboardStatsRepositoryEloquent.php`: solo `count`/`groupBy` con query builder, sin hidratar modelos. Binding en `AppServiceProvider::register()`.
- `back/app/Services/DashboardStatsService.php`:
```php
class DashboardStatsService {
    public function __construct(private DashboardStatsRepositoryInterface $repo) {}
    public function forAdmin(): array; // compone: Cache::remember('dashboard:admin') para todo salvo vets.pending, que se consulta en vivo con repo->pendingVets() y se mezcla en el resultado; calcula ventanas UTC, rate = round((delivered+read)/attempts*100,1), null si attempts=0
}
```
- `back/app/Http/Controllers/V1/DashboardController.php` (`ApiResponseTrait`): `admin(): JsonResponse` con try/catch + `makeSuccess(new DashboardAdminResource($data))` / `makeFromException`.
- `back/app/Http/Resources/V1/DashboardAdminResource.php`: define el shape; sin ids internos. `generated_at` refiere a la parte cacheada; `vets.pending` es en vivo.
- `back/routes/api/dashboard.php`:
```php
Route::prefix('v1/admin')->middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'admin'])->middleware('can:dashboard.admin.read');
});
```
- `back/database/migrations/2026_10_14_000001_add_dashboard_admin_read_permission.php`: clon del patrón de `2026_10_13_000003`; `guid` explícito, guard `web`, `forgetCachedPermissions()`, `down()` verificado.

### Archivos a modificar
- `AppServiceProvider.php`: bind del repository.
- `PermissionSeeder.php`, `RoleSeeder.php`: `dashboard.admin.read` (super-admin ya usa `Permission::all()`; agregar explícito a `admin`).

### Contrato del endpoint
`GET /v1/admin/dashboard` -> 200:
```json
{ "success": true, "data": {
  "generated_at": "2026-10-14T12:00:00Z",
  "vets": { "active": 0, "pending": 0, "suspended": 0, "total": 0 },
  "totals": { "clients": 0, "establishments": 0, "animals": 0, "active_programs": 0 },
  "sent_last_7d": [{ "date": "YYYY-MM-DD", "count": 0 }],
  "delivery_30d": { "rate": 92.5, "by_channel": [{ "channel": "whatsapp", "ok": 0, "failed": 0, "rate": 0 }] }
} }
```
Errores: 401, 403 (sin permiso / rol tenant), 500 estándar. `channel` se devuelve como valor de enum; el FE lo traduce por i18n.

### Tests a generar (backend-tester)
- `DashboardAdminControllerTest`: 200 con shape completo para super-admin y admin; 403 con rol tenant y `operador` (no tiene el permiso); 401 sin token; conteos de vets (activa/pendiente/suspendida); programas cancelados excluidos; `rate` null sin recipients; excluye pending/suppressed.
- `DashboardStatsServiceTest` (unit): cálculo de tasa y cache hit (segunda llamada no consulta el repo salvo `pendingVets()`, que se llama siempre; validar una empresa entre dos llamadas cambia `pending` sin esperar el TTL).
- Test de migración de permisos (patrón `ProgramsAlertsReadPermissionMigrationTest`): idempotencia y asignación solo a super-admin/admin (nunca a `operador`).
- Correr con PHP 8.4 de Laragon explícito.

## Cambios en FRONTEND

Módulo `front/src/modules/dashboard/` (admin):
- `types/dashboard.types.ts`: reemplazar tipos mock por `AdminDashboardData`, `SentPerDay`, `ChannelDelivery` (espejo del Resource; sin `any`).
- `api/dashboard.api.ts`: eliminar mocks; `getAdminDashboardApi()` -> `http.get('/admin/dashboard')` (verificar prefijo `/v1` del cliente `http`; el interceptor desenvuelve `data`).
- `composables/`: borrar `useMetrics`, `useAlerts`, `useActivity`, `useChart`; crear `useAdminDashboard.ts` (`queryKey ['dashboard','admin']`, `staleTime` 5 min).
- Eliminar `AlertsTable.vue`, `ActivityFeed.vue` y los duplicados legacy en `front/src/components/dashboard/` y `store/dashboard.ts` solo si `rg` confirma que no tienen otros usos.
- Componentes nuevos: `DeliveryRateCard.vue` (`a-progress` por canal), `SentBarChart.vue` (wrapper de `TrendChart` con labels de fecha). Reusar `MetricCard` y `AsyncContent`.
- `pages/DashboardPage.vue`: `MetricCard` A1-A6 + `SentBarChart` (A7) + `DeliveryRateCard` (A8); refetch y `generated_at`. Quitar badge falso "Sistema en línea" y tendencias inventadas.
- `AppMenu.vue`: item Dashboard con `permission: 'dashboard.admin.read'`.
- i18n: `front/src/i18n/locales/es/dashboard.ts` (incluye etiquetas de `channel`); sin strings hardcodeados.
- Estados: loading/error con retry vía `AsyncContent`; empty state por bloque; `rate: null` -> "—".

Tests FE (frontend-tester): composable con `dashboard.api` mockeada; página con data completa, loading, error+retry, todo en cero y rate null; item de menú oculto sin permiso; formato de fechas y porcentajes.

## Orden de implementación
1. Migración de permisos + seeders + test de migración.
2. Interface y repository Eloquent + binding + tests del repository.
3. `DashboardStatsService` con cache + unit tests.
4. Resource, controller, `routes/api/dashboard.php` + feature tests.
5. FE: tipos, api, composables; borrar mocks y legacy.
6. FE: componentes, `DashboardPage`, menú, i18n.
7. Tests FE; QA backend/frontend; `review-risk` (permisos) antes del PR.

## Riesgos y consideraciones
- Timezone: `sent_at` es UTC (DB pinned UTC); agrupar por día en PHP en UTC, sin `DATE()` dependiente de sesión.
- Performance: `alert_recipients` crecerá y `sent_at` no tiene índice dedicado; con cache 5 min es aceptable, evaluar índice `(status, sent_at)` si crece.
- Multi-tenant: endpoint exclusivamente de plataforma; no debe montarse bajo `vet.tenant` ni ser accesible a roles tenant (cubierto por permiso + test 403).

## Decisiones abiertas
Ninguna. Cerradas: `operador` sin acceso al dashboard admin; cache 5 min con A2 en vivo.

## Fuera de alcance / futuro
- Dashboard tenant (endpoint `/v1/vets/{vet}/dashboard`, permiso `dashboard.tenant.read`, página FE) con métricas T1-T10 (clientes, establecimientos, animales, programas, fechas objetivo, avisos programados/enviados, entrega, fallos, plan sanitario del mes). Diseño previo disponible en el historial de este plan.
- A9 Fallos 30d por `failure_code` (top 5) y A10 Mensajes de soporte abiertos por prioridad.
- Tendencias vs. período anterior, filtros por rango, export, usuarios por rol.
