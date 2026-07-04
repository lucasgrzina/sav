# TKT-003 - Gestión de Staff de Clientes (Client Staff)

## Tipo
Feature nueva — replicación y adaptación del módulo de staff de veterinarias para clientes

## Contexto
El sistema SAV ya tiene gestión completa de staff para veterinarias (`VetStaffController`, `VetStaffSection.vue`, composables, etc.). Los clientes (establecimientos) también necesitan gestionar sus propios usuarios: dueños (`client-owner`), encargados de campo (`client-manager`) y administrativos (`client-administrative`). Actualmente existe `OwnersSection.vue` como solución parcial que solo cubre `client-owner` con operaciones limitadas. Esta feature reemplaza esa solución con un módulo completo simétrico al de vets, permitiendo al vet invitar, asignar, bloquear y eliminar miembros del staff de cada cliente.

## Estado actual

**Backend:**
- Existe `ClientOwnerController` con solo `index` y `store` bajo el permiso `clients.owners.read/create`.
- `UserProfileService` tiene `listOwnersForClient()` pero no tiene métodos `findByGuidForClient`, `lookupForClient`, `createAndAssignClientStaff`, ni `addMemberToClient`.
- Los permisos `clients.owners.read` y `clients.owners.create` existen en `PermissionSeeder` pero son insuficientes (no cubren update/delete/staff completo).
- No existe `ClientStaffController` ni métodos de staff en `AdminClientController`.

**Frontend:**
- `OwnersSection.vue` existe en `front/src/modules/clients/components/` y se usa en `ClientDetailPage.vue` bajo el tab "Owners".
- No existen composables de staff para clientes, ni tipos específicos de `ClientStaffItem`, ni API file `client-staff.api.ts`.
- `AdminClientDetailPage.vue` no tiene tab de staff (tiene un comentario R-01 aclarando que las secciones dependen de `vetGuid` y se implementarán en iteración futura — esta feature resuelve eso).
- No existen rutas para páginas de creación/edición de staff de cliente.

---

## Decisiones tomadas (no negociables)

### DEC-NEG-01 — OwnersSection.vue se reemplaza por ClientStaffSection.vue
`OwnersSection.vue` y `OwnerFormModal.vue` se reemplazan completamente por la nueva `ClientStaffSection.vue`. La referencia a `OwnersSection` en `ClientDetailPage.vue` (tab "Owners") se sustituye por `ClientStaffSection` renombrando el tab a "Staff". No se mantiene retrocompatibilidad con la implementación de owners.

### DEC-NEG-02 — Sin abstracción genérica entre vet staff y client staff
No se crea ningún componente, composable ni servicio genérico compartido entre los módulos de vet-staff y client-staff. Se copia y adapta la implementación del módulo vet. La duplicación es intencional para mantener independencia evolutiva de ambos dominios.

### DEC-NEG-03 — Nuevos permisos: clients.staff.*
Se crean cuatro permisos nuevos en `PermissionSeeder`:
- `clients.staff.read`
- `clients.staff.create`
- `clients.staff.update`
- `clients.staff.delete`

Los permisos existentes `clients.owners.read` y `clients.owners.create` se mantienen sin modificar (no se eliminan para no romper la migración de datos), pero los nuevos endpoints de staff usan exclusivamente los permisos `clients.staff.*`. La asignación de estos permisos a los roles `vet`, `vet-assistant`, `vet-administrative` (y `superadmin`) debe quedar en el seeder o en una migración de roles según la convención del proyecto.

### DEC-NEG-04 — Roles válidos para client staff
`CLIENT_STAFF_ROLES = ['client-owner', 'client-manager', 'client-administrative']`. Estos roles ya existen en la base de datos. El backend debe validar que el `role_guid` recibido en los requests corresponde a uno de estos tres roles al operar sobre staff de cliente.

### DEC-NEG-05 — UserProfile es polimórfico: solo agregar métodos al servicio
`UserProfile` ya es polimórfico. No se modifica el modelo ni la tabla. Se agregan métodos al `UserProfileService` con scope de `Client`:
- `listForClient(Client $client): Collection`
- `findByGuidForClient(string $guid, Client $client): ?UserProfile`
- `lookupForClient(string $email, Client $client): array`
- `createAndAssignClientStaff(Client $client, array $data): UserProfile`
- `addMemberToClient(Client $client, User $user, Role $role): UserProfile`

### DEC-NEG-06 — Rutas del controlador tenant
El `ClientStaffController` se monta bajo `/v1/vets/{vet}/clients/{client}/staff` dentro del grupo `auth:sanctum` + `vet.tenant`. El parámetro `{client}` identifica al cliente por GUID (nunca por ID interno). Métodos: `index`, `store`, `show`, `update`, `destroy`, `lookup`, `createAndAssign`, `changeRole`, `toggleBlock`.

### DEC-NEG-07 — Rutas del controlador admin
Los métodos de staff en `AdminClientController` se registran bajo `/v1/admin/clients/{guid}/staff` con el middleware `auth:sanctum` + permisos `clients.staff.*`. No llevan `vet.tenant`. Métodos: `staffIndex`, `staffStore`, `staffShow`, `staffUpdate`, `staffChangeRole`, `staffDestroy`.

### DEC-NEG-08 — Panel admin: solo lectura/edición, sin botón "agregar nuevo"
`AdminClientStaffSection.vue` no expone el botón de agregar miembro. El admin puede ver el staff y editar roles, pero no puede crear usuarios nuevos o invitar desde el panel admin. Solo el vet desde su panel puede hacerlo.

### DEC-NEG-09 — Identificadores en URLs y payloads: siempre GUID
Todas las URLs y payloads usan `guid`. Los tipos frontend (`ClientStaffItem`, etc.) exponen `guid`, nunca `id` numérico interno.

### DEC-NEG-10 — Comportamiento de toggleBlock
Un vet puede bloquear/desbloquear un miembro del staff de un cliente que le pertenece (scoped al tenant). Un vet no puede bloquearse a sí mismo. La lógica es simétrica a `VetStaffController.toggleBlock()`.

### DEC-NEG-11 — Archivos de referencia canónicos
El arquitecto DEBE usar estos archivos como espejo directo:
- Backend controller: `back/app/Http/Controllers/V1/VetStaffController.php`
- Backend admin methods: `back/app/Http/Controllers/V1/AdminVetController.php` (métodos `staffIndex`/`staffStore`/`staffShow`/`staffUpdate`/`staffChangeRole`/`staffDestroy`)
- Backend service: `back/app/Services/UserProfileService.php`
- Backend requests: `back/app/Http/Requests/Members/AssignVetStaffRequest.php` et al.
- Frontend API: `front/src/modules/vets/api/vet-staff.api.ts`
- Frontend component: `front/src/modules/vets/components/VetStaffSection.vue`
- Frontend composables: `front/src/modules/vets/composables/useAdminVetStaff.ts` et al.

---

## Decisiones que el arquitecto debe tomar

### A definir 1 — Resolución del parámetro `{client}` en el middleware vet.tenant
El grupo `vet.tenant` ya resuelve el parámetro `{vet}` y lo inyecta como `current_vet` en `$request->attributes`. El arquitecto debe determinar cómo el `ClientStaffController` obtiene el modelo `Client` desde el parámetro de ruta `{client}` (GUID), verificando que el client efectivamente pertenece al vet autenticado antes de operar. Debe ser consistente con cómo `EstablishmentController` y `ContactController` resuelven su parámetro `{client}` (ver `back/routes/api/clients.php` línea 50).

### A definir 2 — Lookup: shape del response y status cuando el usuario ya es miembro
El `lookupForClient` debe informar al frontend si el email encontrado: (a) no existe en el sistema, (b) existe pero no está vinculado al cliente, o (c) ya es miembro del cliente. El arquitecto debe definir el shape del response (probablemente `{ status: 'not_found' | 'available' | 'already_member', profile?: UserProfileResource }`) replicando la lógica de `lookupForVet` en `UserProfileService` pero adaptada al scope de `Client`.

### A definir 3 — Validación de role_guid en los Form Requests de client staff
Los requests `AssignClientStaffRequest`, `CreateClientStaffRequest`, `ChangeClientStaffRoleRequest` deben validar que el `role_guid` corresponde a un rol de `CLIENT_STAFF_ROLES`. El arquitecto debe decidir si esta validación va en el Form Request (query a DB por nombre del rol) o en el Service (throw `RuntimeException`), siendo consistente con cómo lo hacen los requests equivalentes de vet-staff.

### A definir 4 — Organización de los Form Requests nuevos
Los requests existentes de vet-staff viven en `back/app/Http/Requests/Members/`. El arquitecto debe decidir si los nuevos requests de client-staff van en el mismo namespace (`Members`) o en un subdirectorio nuevo (`Members/Client`), siendo consistente con la convención del proyecto.

### A definir 5 — Query keys de Vue Query para client staff
El arquitecto debe definir las query keys para los composables de Vue Query de client staff, asegurando que sean distintas de las query keys de vet-staff y que el `invalidateQueries` post-mutación invalide solo las queries del cliente correcto (evitar invalidaciones globales).

### A definir 6 — Ubicación de los nuevos tipos TypeScript
Los tipos de client staff (`ClientStaffItem`, `ClientStaffRoleItem`, `ClientStaffCreatePayload`, etc.) deben vivir en el archivo de tipos del módulo clients. El arquitecto debe verificar si existe `front/src/modules/clients/types/client.types.ts` o equivalente y agregar allí los nuevos tipos, o crear el archivo si no existe.

### A definir 7 — Ruta de la página de creación de staff (lookup + create/assign)
La página `ClientStaffCreatePage.vue` (flujo lookup → crear nuevo usuario o asignar existente) necesita una ruta con params `vetGuid` y `clientGuid`. El arquitecto debe definir la URL canónica (ej: `/vets/:vetGuid/clients/:clientGuid/staff/new`) verificando que no colisione con rutas existentes en `clients.routes.ts`, replicando la convención de la página equivalente en el módulo vets.

---

## Restricciones

- Toda operación de `ClientStaffController` (tenant) DEBE estar scoped al `current_vet` autenticado: un vet solo puede gestionar staff de sus propios clientes. Nunca operar sin filtrar por tenant.
- El parámetro `{client}` en las rutas es siempre el GUID del cliente, nunca el ID numérico.
- Los permisos `clients.owners.read` y `clients.owners.create` NO se deben eliminar ni modificar en este ticket. Solo se agregan los nuevos `clients.staff.*`.
- `ClientOwnerController` NO se elimina en este ticket. Se puede deprecar en un ticket posterior si el equipo decide consolidar.
- El frontend NO puede inventar endpoints. Todos los endpoints deben estar definidos en el backend antes de ser consumidos.
- No implementar paginación en este ticket: la lista de staff de un cliente se retorna completa (igual que vet-staff hoy).
- No implementar envío de notificaciones/alertas al agregar staff en este ticket. Solo la invitación por email que ya dispara el mecanismo existente de `SendClientOwnerInvitationJob` o equivalente para los nuevos roles.
- `AdminClientStaffSection.vue` no tiene botón de agregar (DEC-NEG-08).
- Los tres roles `client-owner`, `client-manager`, `client-administrative` ya existen en la DB y no deben crearse nuevamente.

---

## Investigación previa que el arquitecto debe hacer

1. Leer `back/app/Services/UserProfileService.php` completo para entender los métodos existentes (`list`, `findByGuidForVet`, `lookupForVet`, `createAndAssignVetStaff`, `addMember`) y replicarlos para el scope `Client`.
2. Leer `back/app/Http/Controllers/V1/VetStaffController.php` completo — es el espejo exacto del `ClientStaffController` a crear.
3. Leer los métodos `staffIndex`, `staffStore`, `staffShow`, `staffUpdate`, `staffChangeRole`, `staffDestroy` en `back/app/Http/Controllers/V1/AdminVetController.php` — son el modelo para los métodos a agregar en `AdminClientController`.
4. Verificar cómo `ContactController` y `EstablishmentController` resuelven el modelo `Client` desde el parámetro de ruta `{client}` para replicar ese mecanismo en `ClientStaffController` (ver `back/routes/api/clients.php` líneas 50–63).
5. Leer `back/app/Http/Requests/Members/AssignVetStaffRequest.php`, `CreateVetStaffRequest.php`, `UpdateVetStaffRequest.php`, `ChangeVetStaffRoleRequest.php` — modelo de los nuevos requests.
6. Verificar en `PermissionSeeder` la estructura exacta de cómo se asignan permisos a roles (si se hace en el seeder o en una migración separada) para seguir la misma convención al agregar `clients.staff.*`.
7. Leer `front/src/modules/vets/api/vet-staff.api.ts` — espejo de `client-staff.api.ts` a crear (prestar atención a los endpoints de admin vs tenant y la convención de nombres de funciones).
8. Verificar si existe `front/src/modules/clients/types/client.types.ts` y su contenido actual para agregar los nuevos tipos sin duplicar los existentes.
9. Leer `front/src/modules/vets/composables/` para identificar todos los composables de vet-staff existentes y replicar su estructura en el módulo clients.
10. Verificar la convención de rutas del módulo vets para páginas de staff (`VetStaffCreatePage`, `VetEditStaffPage`) para definir las URLs análogas de client-staff en `clients.routes.ts`.
11. Leer `front/src/modules/clients/components/OwnersSection.vue` para identificar qué lógica/props hay que migrar a `ClientStaffSection.vue` y qué se puede desechar.
12. Confirmar en `AdminClientDetailPage.vue` el comentario R-01 (línea 80) que marca explícitamente que la sección de staff se implementará en iteración futura — este ticket es esa iteración.

---

## Inventario completo de artefactos a crear/modificar

### Backend — nuevos artefactos
- `back/app/Http/Controllers/V1/ClientStaffController.php` (nuevo)
- `back/app/Http/Requests/Members/AssignClientStaffRequest.php` (nuevo)
- `back/app/Http/Requests/Members/CreateClientStaffRequest.php` (nuevo)
- `back/app/Http/Requests/Members/UpdateClientStaffRequest.php` (nuevo)
- `back/app/Http/Requests/Members/ChangeClientStaffRoleRequest.php` (nuevo)
- Métodos en `back/app/Services/UserProfileService.php`: `listForClient`, `findByGuidForClient`, `lookupForClient`, `createAndAssignClientStaff`, `addMemberToClient` (modificar)
- Métodos en `back/app/Http/Controllers/V1/AdminClientController.php`: `staffIndex`, `staffStore`, `staffShow`, `staffUpdate`, `staffChangeRole`, `staffDestroy` (modificar)
- `back/database/seeders/PermissionSeeder.php`: agregar `clients.staff.read/create/update/delete` (modificar)
- `back/routes/api/clients.php`: rutas nuevas para `ClientStaffController` (tenant) y métodos staff de `AdminClientController` (modificar)

### Frontend — nuevos artefactos
- `front/src/modules/clients/api/client-staff.api.ts` (nuevo)
- Tipos en `front/src/modules/clients/types/client.types.ts`: `ClientStaffItem`, `ClientStaffRoleItem`, `ClientStaffCreatePayload`, `ClientStaffAssignPayload`, `UpdateClientStaffPayload`, `ClientStaffLookupResult` (modificar)
- `front/src/modules/clients/composables/admin/useAdminClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/admin/useAdminClientStaffMember.ts` (nuevo)
- `front/src/modules/clients/composables/admin/useAdminAssignClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/admin/useAdminChangeClientStaffRole.ts` (nuevo)
- `front/src/modules/clients/composables/admin/useAdminRemoveClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/admin/useAdminUpdateClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/useClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/useClientStaffMember.ts` (nuevo)
- `front/src/modules/clients/composables/useLookupClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/useCreateClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/useAssignClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/useChangeClientStaffRole.ts` (nuevo)
- `front/src/modules/clients/composables/useToggleClientStaffBlock.ts` (nuevo)
- `front/src/modules/clients/composables/useRemoveClientStaff.ts` (nuevo)
- `front/src/modules/clients/composables/useUpdateClientStaff.ts` (nuevo)
- `front/src/modules/clients/components/admin/AdminClientStaffSection.vue` (nuevo — sin botón agregar)
- `front/src/modules/clients/components/ClientStaffSection.vue` (nuevo — con botón agregar, reemplaza OwnersSection.vue)
- `front/src/modules/clients/components/ClientStaffTable.vue` (nuevo — acciones: cambiar rol, editar, bloquear/desbloquear, eliminar)
- `front/src/modules/clients/components/ClientStaffEditForm.vue` (nuevo)
- `front/src/modules/clients/pages/admin/AdminClientEditStaffPage.vue` (nuevo)
- `front/src/modules/clients/pages/ClientStaffCreatePage.vue` (nuevo — flujo lookup + create/assign)
- `front/src/modules/clients/pages/ClientEditStaffPage.vue` (nuevo)
- `front/src/modules/clients/pages/ClientDetailPage.vue`: reemplazar tab "Owners" + `OwnersSection` por tab "Staff" + `ClientStaffSection` (modificar)
- `front/src/modules/clients/pages/admin/AdminClientDetailPage.vue`: agregar tab "Staff" con `AdminClientStaffSection` (modificar)
- `front/src/modules/clients/router/clients.routes.ts`: rutas para `ClientStaffCreatePage` y `ClientEditStaffPage` (modificar)
- `front/src/modules/clients/router/admin-clients.routes.ts`: ruta para `AdminClientEditStaffPage` (modificar)

### Frontend — artefactos a eliminar o deprecar
- `front/src/modules/clients/components/OwnersSection.vue` (eliminar — reemplazado por ClientStaffSection)
- `front/src/modules/clients/components/modals/OwnerFormModal.vue` (eliminar — funcionalidad migrada a ClientStaffSection + ClientStaffCreatePage)

---

## Output esperado

Plan técnico en `.claude/docs/plans/TKT-003-client-staff-gestion-plan.md` que incluya:

- Implementación completa del backend en orden: (1) métodos en `UserProfileService`, (2) Form Requests, (3) `ClientStaffController`, (4) métodos staff en `AdminClientController`, (5) rutas en `clients.php`, (6) permisos en `PermissionSeeder`.
- Implementación completa del frontend en orden: (1) tipos en `client.types.ts`, (2) `client-staff.api.ts`, (3) todos los composables admin, (4) todos los composables tenant, (5) componentes (`AdminClientStaffSection`, `ClientStaffSection`, `ClientStaffTable`, `ClientStaffEditForm`), (6) páginas (`AdminClientEditStaffPage`, `ClientStaffCreatePage`, `ClientEditStaffPage`), (7) modificaciones a `AdminClientDetailPage` y `ClientDetailPage`, (8) rutas en los dos archivos de router.
- Resolución de A-definir-1 (cómo se resuelve el modelo Client en el controlador tenant).
- Resolución de A-definir-2 (shape del response del lookup).
- Resolución de A-definir-3 (validación de role_guid en requests).
- Resolución de A-definir-5 (query keys Vue Query).
- Resolución de A-definir-7 (URL de ClientStaffCreatePage).
- Lista de archivos a eliminar (`OwnersSection.vue`, `OwnerFormModal.vue`) con confirmación de que no hay otros consumidores antes de borrarlos.
