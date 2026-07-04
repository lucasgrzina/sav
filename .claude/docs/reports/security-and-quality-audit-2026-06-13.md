# Reporte: Auditoría de Seguridad y Calidad — SAV
**Fecha:** 2026-06-13  
**Auditores:** Agente Arquitecto (orquestación) + Agente Seguridad  
**Módulos auditados:** users, roles, support-messages, exports, auth, config, models

---

## Resumen ejecutivo

La auditoría identificó 2 vulnerabilidades críticas, 3 altas, 4 medias y varios hallazgos bajos.  
Todos los ítems fueron corregidos en la misma sesión, **excepto 2 que requieren refactor mayor** y quedaron pendientes.

La postura de seguridad general era buena (CORS bien configurado, brute force protection existente, rate limiting en auth). Los problemas más graves fueron tokens eternos, XSS confirmado en el modal de soporte, y user enumeration por mensajes explícitos.

---

## IMPLEMENTADO

### CRÍTICOS

#### 1. Tokens Sanctum sin expiración → `back/config/sanctum.php` + `back/app/Services/AuthService.php`
- **Problema:** `'expiration' => null` y `createToken('api-access', ['*'])` sin fecha de vencimiento. Tokens válidos indefinidamente.
- **Fix aplicado:**
  - `sanctum.php`: `'expiration' => env('SANCTUM_ACCESS_TOKEN_EXPIRATION', 1440)` (24h por defecto)
  - `AuthService.php`: `createToken(..., ['*'], now()->addMinutes($expiration))`
  - `.env.example`: agregado `SANCTUM_ACCESS_TOKEN_EXPIRATION=1440`
  - Eliminada clave redundante `access_token_expiration` de `sanctum.php`

#### 2. XSS vía `v-html` sin sanitizar → `front/src/modules/support-messages/components/modals/SupportMessageDetailModal.vue`
- **Problema:** `v-html="thread.body"` y `v-html="reply.body"` renderizaban HTML crudo. Un atacante podía inyectar `<script>` en mensajes de soporte.
- **Fix aplicado:**
  - Creado `front/src/core/composables/useSanitize.ts` con `DOMPurify.sanitize(html, { USE_PROFILES: { html: true } })`
  - Modal actualizado: `v-html="sanitize(thread.body)"` y `v-html="sanitize(reply.body)"`
  - Instalado `dompurify` + `@types/dompurify` via npm

---

### ALTOS

#### 3. User Enumeration en login → `back/app/Http/Requests/LoginRequest.php`
- **Problema:** Regla `'exists:users,email'` fallaba con "No existe una cuenta con ese email." antes de verificar contraseña. Permitía enumerar emails válidos.
- **Fix aplicado:**
  - Removida regla `exists:users,email` de `rules()`
  - Removido mensaje `email.exists` de `messages()`
  - Agregado en `withValidator`: si `$this->user === null` → error genérico `'Credenciales inválidas.'`

#### 4. Rate limiting demasiado permisivo en login → `back/routes/api/auth.php` + `back/app/Providers/AppServiceProvider.php`
- **Problema:** `throttle:10,1` = 10 intentos/min por IP. Con rotación de IPs: cientos de intentos/hora sobre una sola cuenta.
- **Fix aplicado:**
  - Register: `throttle:5,1` (por IP)
  - Login: `throttle:login` (named rate limiter)
  - `AppServiceProvider::boot()`: `RateLimiter::for('login', fn($req) => Limit::perMinute(5)->by($req->input('email', '').$req->ip()))` — limita por `email+ip` combinados

#### 5. Dominios Sanctum stateful con defaults amplios → `.env.example`
- **Problema:** Sin `SANCTUM_STATEFUL_DOMAINS` explícito en producción, el default incluye rangos de localhost.
- **Fix aplicado:** `.env.example` ahora incluye `SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173` con comentario indicando reemplazar con dominio real en producción.

---

### MEDIOS

#### 6. `APP_DEBUG=true` en `.env.example` → `back/.env.example`
- **Fix:** Cambiado a `APP_DEBUG=false`

#### 7. Mass assignment con campos de seguridad en `$fillable` → `back/app/Models/User.php`
- **Problema:** `failed_login_attempts`, `locked_at`, `email_verified_at` en `$fillable` permitían manipulación vía mass assignment.
- **Fix:** Removidos de `$fillable`. Solo actualizables via asignación directa en servicios (ya era así en la práctica).

#### 8. Session encryption desactivada → `back/.env.example`
- **Fix:** `SESSION_ENCRYPT=true` en `.env.example`

#### 9. Token de auth persistido en localStorage → `front/src/modules/auth/stores/auth.store.ts`
- **Problema:** `persist: true` persistía el token en localStorage, exponiéndolo a XSS.
- **Fix:** `persist: { pick: ['user', 'mustVerifyAccount', 'pendingVerificationGuid', 'mustChangePassword'] }` — token solo en memoria de sesión.

---

### BAJOS / CALIDAD

#### 10. `id` numérico expuesto en API de usuarios → `back/app/Http/Resources/V1/UserResource.php`
- **Fix:** Removido `'id' => $this->id`. El `guid` es el identificador público.

#### 11. `id` en tipos y mapper del frontend → `front/src/modules/users/types/user.types.ts` + `front/src/modules/users/api/users.mapper.ts`
- **Fix:** Removido `id: number` del interface `User` y del mapper `toUserItem()`.

#### 12. `permissions_count` faltante en `RoleResource` → `back/app/Http/Resources/V1/RoleResource.php`
- **Problema:** `ROLE_EXPORT_COLUMNS` declaraba `permissions_count` pero el Resource no lo emitía.
- **Fix:** Agregado `'permissions_count' => $this->whenCounted('permissions')`

#### 13. Parámetro de ruta inconsistente en roles → `back/routes/api/roles.php`
- **Fix:** `.parameters(['roles' => 'role'])` → `.parameters(['roles' => 'guid'])` (consistente con el resto del sistema)

#### 14. `MAIL_FROM_ADDRESS` con valor placeholder → `back/.env.example`
- **Fix:** Cambiado `"hello@example.com"` a `"noreply@your-domain.com"`

#### 15. Dependencias con vulnerabilidades conocidas → `back/composer.json` + `composer.lock`
- **Problema:** `roave/security-advisories` detectó 14 advisories en 9 paquetes, incluyendo `laravel/framework v12.58.0` (vulnerabilidad en `illuminate/mail`), `guzzlehttp/psr7`, `phpoffice/phpspreadsheet`, `symfony/http-foundation`, `symfony/yaml`.
- **Fix:** `composer update` completo — todos los paquetes actualizados a versiones parcheadas. Instalado `roave/security-advisories:dev-latest` en `require-dev` para bloquear deps vulnerables en el futuro.
- **Resultado post-fix:** `composer audit` → `No security vulnerability advisories found`

---

## PENDIENTE (requiere refactor mayor)

### P1 — Global Scopes para aislamiento multi-tenant
- **Riesgo:** El middleware `EnsureUserBelongsToVet` inyecta el tenant en `$request->attributes` pero no hay `GlobalScope` en los modelos tenant-dependientes. El aislamiento depende de que cada repositorio recuerde aplicar `.where('vet_id', $vet->id)` manualmente. Un error de omisión en cualquier query expone datos cross-tenant.
- **Fix propuesto:** Crear trait `BelongsToTenant` con `addGlobalScope` en modelos como `Client`, `Establishment`, `Contact`, etc.
- **Por qué no se implementó:** Requiere identificar todos los modelos con `vet_id`, entender cómo el middleware resuelve el vet en el contexto del scope, y testear que no rompa queries admin que intencionalmente ven todos los tenants.
- **Acción:** Crear ticket para implementar como feature separada con tests de aislamiento.

### P2 — Canal WebSocket por GUID en lugar de ID numérico
- **Riesgo bajo:** El canal `app.user.{id}` expone el ID interno en la conexión WebSocket. El riesgo es bajo porque el canal es privado y autenticado via Sanctum.
- **Fix propuesto:** Migrar a `app.user.{guid}` en `channels.php`, `Events/News.php`, `NotificationService.php`, `socket.service.ts`, `App.vue`, y el modelo `Notification` (que guarda `user_ids` como int[]).
- **Por qué no se implementó:** Cambio en cascada que afecta notifications, events, y el frontend del canal. Requiere migración de datos o manejo dual durante transición.
- **Acción:** Evaluar en conjunto con la implementación de notificaciones si está planificada como feature.

---

## Notas adicionales

- El tipo `User` en `auth.types.ts` conserva `id: number` porque es necesario para `socketService.connect(userId)` (canal WebSocket). Esto es intencional hasta que P2 sea implementado.
- `SupportMessageListParams` no requiere cambios: el `SupportMessageController::index` solo acepta `per_page`, sin filtros adicionales.
- El type-check de Vue TSC post-modificaciones pasó sin errores.
- Los tests de Laravel (2 tests básicos de scaffolding) pasan correctamente.
