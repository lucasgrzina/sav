# Esquema de Agentes — SAV (Sistema de Alertas Veterinarias)

**Fecha:** 2026-05-22  
**Estado:** Revisado y adaptado al dominio SAV. Agentes de Alphinance eliminados.

---

## 1. Inventario de agentes activos

| # | Nombre | Archivo | Tipo |
|---|--------|---------|------|
| 1 | `funcional` | `agents/funcional.md` | Análisis / Especificación |
| 2 | `ticket-builder` | `agents/ticket-builder.md` | Análisis / Tickets |
| 3 | `arquitecto` | `agents/arquitecto.md` | Diseño técnico |
| 4 | `dev-backend` | `agents/dev-backend.md` | Implementación |
| 5 | `backend-module-gen` | `agents/backend-module-gen.md` | Generación |
| 6 | `frontend-module-gen` | `agents/frontend-module-gen.md` | Generación |
| 7 | `qa-backend` | `agents/qa-backend.md` | Calidad |
| 8 | `qa-frontend` | `agents/qa-frontend.md` | Calidad |
| 9 | `ui-specialist` | `agents/ui-specialist.md` | UI/UX |
| 10 | `backend-tester` | `agents/backend-tester.md` | Testing |
| 11 | `frontend-tester` | `agents/frontend-tester.md` | Testing |

---

## 2. Pipelines de trabajo

### 2.1 Feature nueva (PRD → producción)

```
┌──────────┐    ┌──────────────┐    ┌────────────┐    ┌─────────────┐    ┌────────────────────┐
│ funcional│ →  │  arquitecto  │ →  │dev-backend │ →  │   testers   │ →  │    revisores QA    │
└──────────┘    └──────────────┘    └────────────┘    └─────────────┘    └────────────────────┘
  PRD →           spec →              plan →             código →          backend-tester
  spec            plan                repo               tests             frontend-tester
                                                                           qa-backend
                                                                           qa-frontend
```

### 2.2 Bug / mejora acotada

```
┌───────────────┐    ┌──────────────┐    ┌────────────┐
│ ticket-builder│ →  │  arquitecto  │ →  │dev-backend │
└───────────────┘    └──────────────┘    └────────────┘
  req. vago →          ticket →            plan →
  ticket               plan                código
```

### 2.3 Módulo nuevo desde cero

```
┌───────────────┐    ┌──────────────┐    ┌────────────────────┐    ┌─────────────────────┐
│ ticket-builder│ →  │  arquitecto  │ →  │ backend-module-gen │ →  │ frontend-module-gen │
└───────────────┘    └──────────────┘    └────────────────────┘    └─────────────────────┘
                                                   ↓                           ↓
                                             qa-backend                  qa-frontend
```

---

## 3. Responsabilidades detalladas

### funcional
- **Rol:** Analista funcional especializado en el dominio veterinario/sanitario SAV
- **Input:** PRD en `.claude/docs/prds/`
- **Output:** Spec funcional en `.claude/docs/specs/[nombre]-spec.md`
- **Acciones:**
  - Lee `.claude/knowledge/veterinary-domain.md` y `regulations-by-country.md` antes de analizar
  - Identifica impacto en: protocolos/tareas, alertas, planes sanitarios, programas, animales/establecimientos, roles, multi-tenant, multi-país
  - Detecta y alerta: `days_offset` faltante, roles de alerta sin definir, año ganadero vs calendario, scope de tenant ausente, multi-país no contemplado, IDs numéricos en URLs
- **NO hace:** proponer código, endpoints, tablas o servicios

### ticket-builder
- **Rol:** Convierte requerimientos vagos en tickets técnicos accionables
- **Input:** Lenguaje natural del usuario
- **Output:** Ticket en `.claude/docs/tickets/TKT-XXX-nombre-corto.md`
- **Acciones:**
  - Pregunta de a UNA con opciones cerradas numeradas
  - Frena si una decisión viola reglas duras del dominio SAV
  - Separa DEC-NEG (decisiones del usuario) de "A definir" (decisiones del arquitecto)
  - Si el req es enorme (debería ser PRD), redirige al agente `funcional`
- **NO hace:** proponer soluciones técnicas

### arquitecto
- **Rol:** Tech lead — diseña la implementación a nivel ejecutable
- **Input:** Spec (`.claude/docs/specs/`) o ticket (`.claude/docs/tickets/`)
- **Output:** Plan técnico en `.claude/docs/plans/[nombre]-plan.md`
- **Acciones:**
  - Grep/Glob exhaustivos para explorar el código real antes de planear
  - Toma decisiones técnicas con justificación — no las escala salvo conflictos irresolubles con reglas duras
  - Plan incluye: decisiones tomadas (DEC-XX), archivos a crear/modificar, contratos de endpoints, orden de implementación, riesgos
  - Valida invariantes SAV: `ProtocolTask.days_offset + time_of_day`, `ProtocolAlert.roles`, scope de tenant en toda query, GUID en URLs, `require_confirmation` en tareas físicas
- **NO hace:** escribir código final completo

### dev-backend
- **Rol:** Implementador full-stack (Laravel + Vue 3)
- **Input:** Plan del arquitecto (`.claude/docs/plans/`) o ticket con diagnóstico definido
- **Output:** Código escrito/modificado en el repo, tests pasando
- **Acciones:**
  - Ejecuta el plan SIN reinterpretar decisiones de diseño
  - Lee cada archivo existente antes de modificarlo
  - Reporta desvíos (archivo no existe, firma distinta) ANTES de continuar
  - Nunca hace migraciones destructivas sin confirmar
  - Paths SAV: `back/` (Laravel), `front/` (Vue 3)
- **NO hace:** cambiar decisiones de diseño, tocar archivos fuera del alcance del plan

### backend-module-gen
- **Rol:** Generador de módulos Laravel completos
- **Input:** Nombre del módulo (PascalCase), descripción, relaciones
- **Output:** 12 archivos creados/modificados en el repo
- **Checklist de 12 pasos:**
  1. Migración (`back/database/migrations/`)
  2. Modelo con `HasGuid`, `getRouteKeyName()`, `$hidden = ['id']`
  3. Seeder (si se pide) — `guid` explícito, `WithoutModelEvents`
  4. Interface del repositorio (`back/app/Contracts/Repositories/`)
  5. Repositorio Eloquent (`back/app/Repositories/`)
  6. Binding en `AppServiceProvider`
  7. Servicio (`back/app/Services/`)
  8. Form Requests — `StoreXxx` + `UpdateXxx` con `messages()` en español
  9. Resource V1 — sin `id`, siempre `guid`
  10. Controller V1 con `ApiResponseTrait`, recibe `string $guid`
  11. Archivo de rutas (`back/routes/api/`) + inclusión en `api.php`
  12. Permisos en `PermissionSeeder` — 4 por módulo, guard `'web'`

### frontend-module-gen
- **Rol:** Generador de módulos Vue 3 completos
- **Input:** Nombre del módulo, shape del Resource backend, operaciones CRUD
- **Output:** Estructura completa en `front/src/modules/{nombre}/`
- **8 sub-carpetas obligatorias:** `api/`, `components/`, `composables/`, `pages/`, `router/`, `stores/`, `types/`, `validators/`
- **Invariantes:**
  - No server state en Pinia (solo UI state: modales, filtros, drawers)
  - Vue Query para lecturas (`useQuery`) y escrituras (`useMutation`)
  - `PermissionGuard` en todas las acciones de escritura
  - i18n obligatorio — sin strings hardcodeados en templates
  - GUID en URLs y payloads, nunca ID numérico
  - Lazy loading en rutas, `authGuard` en `beforeEnter`

### qa-backend
- **Rol:** Revisor de calidad código Laravel
- **Input:** Módulo, archivo o directorio a revisar
- **Output:** Reporte en `.claude/docs/reviews/qa-backend-{nombre}-{fecha}.md`
- **Clasificación de problemas:**
  - **Crítico** (bloquea merge): `id` expuesto, lógica en controller, query sin pasar por repositorio, ruta sin `auth:sanctum`, seeder sin GUID explícito, escritura multi-tabla sin transaction, binding faltante
  - **Mayor**: controller sin `ApiResponseTrait`, modelo sin `HasGuid` o `getRouteKeyName`, Request sin `messages()` en español, Resource con `id`, guard incorrecto
  - **Menor**: namespace incorrecto, FK sin `cascadeOnDelete`, soft deletes, `$id` en controller

### qa-frontend
- **Rol:** Revisor de calidad código Vue 3
- **Input:** Módulo, archivo o directorio a revisar
- **Output:** Reporte en `.claude/docs/reviews/qa-frontend-{nombre}-{fecha}.md`
- **Clasificación de problemas:**
  - **Crítico** (bloquea merge): `any` en TypeScript, server state en Pinia, HTTP directo desde componente, endpoint inventado, `id` numérico en URLs, acción de escritura sin `PermissionGuard`
  - **Mayor**: strings hardcodeados, mutation sin `invalidateQueries`, estado modal/drawer local en vez del UI store, elemento HTML crudo cuando existe átomo, ruta sin lazy loading o sin `authGuard`
  - **Menor**: `<script>` sin `lang="ts"`, props sin tipo, lógica de negocio en componente

### ui-specialist
- **Rol:** Construye/refactoriza componentes UI para el dominio SAV
- **Input:** Descripción de la UI (datos, acciones, rol del usuario)
- **Output:** Componentes `.vue` verificados con type-check
- **Stack:** Ant Design Vue 4 + Tailwind CSS 3 + Vue 3 Composition API
- **Contexto de dominio:** UI de campo = simple y táctil (celular, conectividad limitada). Siempre verifica átomos existentes en `front/src/components/atoms/` antes de crear nuevos.
- **Átomos disponibles:** `BaseInput`, `BaseSelect`, `BasePasswordInput`, `BaseDateRangePicker`, `BaseModal`, `BaseDrawer`, `BaseConfirmDialog`, `BaseDataTable`, `BaseTableActions`

### backend-tester
- **Rol:** Escribe tests PHPUnit/Pest para el backend Laravel
- **Input:** Módulo o archivo a testear
- **Output:** Tests en `back/tests/Feature/{Modulo}/` y `back/tests/Unit/Services/`
- **Reglas:**
  - SQLite in-memory, `RefreshDatabase` en todos los Feature tests
  - `WithoutModelEvents` en seeders → GUID explícito en factories
  - `actingAs($user, 'sanctum')` en endpoints protegidos
  - Guard `'web'` para Spatie en tests
- **Tests obligatorios por endpoint:** 401 sin token, 403 sin permiso, 404 para GUID inexistente, `assertJsonMissing(['id'])` en todos los responses

### frontend-tester
- **Rol:** Escribe tests Vitest + Vue Test Utils para el frontend
- **Input:** Módulo o archivo a testear
- **Output:** Tests coubicados junto a los archivos que testean (`.test.ts`)
- **Mocks:** Vue Query (`QueryClient`), Pinia (`createTestingPinia`), funciones de `api/` (`vi.mock`)
- **Tests obligatorios:** PermissionGuard positivo Y negativo, invalidación de queries en mutations, estados loading / empty / error en tablas, validación de formularios

---

## 4. Archivos de soporte

| Carpeta | Propósito |
|---------|-----------|
| `.claude/docs/prds/` | PRDs de producto (input del `funcional`) |
| `.claude/docs/specs/` | Specs funcionales (output del `funcional`, input del `arquitecto`) |
| `.claude/docs/tickets/` | Tickets técnicos (output del `ticket-builder`, input del `arquitecto`) |
| `.claude/docs/plans/` | Planes de implementación (output del `arquitecto`, input del `dev-backend`) |
| `.claude/docs/reviews/` | Reportes QA (output de `qa-backend` / `qa-frontend`) |
| `.claude/knowledge/veterinary-domain.md` | Dominio veterinario: especies, protocolos, sanidad, roles |
| `.claude/knowledge/regulations-by-country.md` | Regulaciones: SENASA AR, SENASICA MX, ICA CO, SAG CL, MAPA BR |

---

## 5. Reglas duras del dominio SAV (guardadas en todos los agentes relevantes)

1. `ProtocolTask` siempre tiene `days_offset` + `time_of_day` (Before/After)
2. `ProtocolAlert.roles` es un array — valores válidos: `[vet, vet-assistant, client-owner, client-manager]`
3. Año ganadero en AR = julio–junio, NO el año calendario
4. Multi-tenant: toda query scoped al vet autenticado — omitirlo es violación de seguridad
5. Multi-país desde el diseño — no hardcodear lógica argentina sin contemplar equivalentes
6. GUID como identificador en URLs y payloads — nunca el ID interno
7. `require_confirmation` para tareas físicas de campo
8. API es la fuente de verdad del contrato — el frontend no inventa endpoints

---

## 6. Cambios realizados en esta revisión (2026-05-22)

| Agente | Cambio |
|--------|--------|
| `funcional.md` | Reemplazado: era versión Alphinance (finanzas). Ahora = dominio SAV veterinario |
| `ticket-builder.md` | Reemplazado: era versión Alphinance. Ahora = dominio SAV veterinario |
| `funcional-vet.md` | Eliminado — fusionado en `funcional.md` |
| `ticket-vet.md` | Eliminado — fusionado en `ticket-builder.md` |
| `arquitecto.md` | Reescrito: reglas de dominio SAV, paths `back/` y `front/`, invariantes de arquitectura SAV |
| `dev-backend.md` | Reescrito: reglas SAV, paths corregidos, Feature Module Pattern en frontend |
| `helper.md` | Reescrito completo: 11 agentes SAV documentados, pipelines, estructura de carpetas |
