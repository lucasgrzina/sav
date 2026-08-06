# TKT-005 - Sidebar tenant pierde bloques de menu al refrescar o entrar por deep-link

## Tipo
Bug

## Contexto
El sidebar del layout tenant (`VetTenantLayout.vue`) muestra los bloques "Veterinaria" (Perfil/Clientes/Usuarios) y "Reproduccion" (Protocolos/Programas) segun los permisos del tenant activo. Si el usuario ya esta autenticado y entra a una ruta tenant sin pasar por `LoginPage.vue` (F5 o deep-link), esos bloques desaparecen aunque el contenido de la pagina cargue con datos reales, dejando al usuario sin acceso a la navegacion operativa del modulo.

## Estado actual
Reproducido en `/vets/{guid}/programs` y `/vets/{guid}/protocols`:
1. Usuario autenticado, ya con sesion activa.
2. Hace F5 en una ruta tenant, o entra directo por un link a `/vets/{guid}/protocols` (sin pasar por login).
3. La pagina carga y muestra datos reales correctamente.
4. El sidebar (`VetMenu` dentro de `AppSidebar`) queda solo con Mensajes/Tutoriales/Mi perfil. Los bloques "Veterinaria" y "Reproduccion" no aparecen.

Confirmado que es preexistente, no introducido por el modulo Programa implementado en la fecha de este ticket.

## Decisiones tomadas (no negociables)

### DEC-NEG-01: Alcance del fix
Se ataca el bug de perdida de permisos de sidebar en refresh/deep-link. No se extiende el alcance a un rediseno del sidebar ni de `useVetTenant`/`usePermission` mas alla de lo necesario para resolver la causa raiz.

## Decisiones que el arquitecto debe tomar

### A definir 1: Causa raiz del estado "pegado" de `tenantPermissions` vacio
Se confirmo el siguiente flujo, pero falta cerrar por que la ventana de `tenantPermissions` vacio queda pegada en vez de autocorregirse quando el `watchEffect` de `useVetTenant.ts` finalmente resuelve:

- `front/src/router/guards/vetTenantGuard.ts` existe y repuebla `vetStore.userVets` si esta vacio.
- `tenantPermissions` (de donde dependen `hasTenantContext` y `can()` en `usePermission()`) se setea en `useVetTenant.ts` via un `watchEffect` que corre solo al montar `VetTenantLayout.vue`, dependiendo de que `useVetProfile(guid)` resuelva Y de que `vetStore.userVets` ya tenga el vet.
- En `VetTenantLayout.vue`, el `AppSidebar` (que contiene `VetMenu`) se renderiza SIN esperar el `isLoading` de `useVetTenant()`. Solo el `<RouterView>` esta gateado por ese loading. Hay una ventana donde el sidebar se pinta con `tenantPermissions` todavia vacio.

Candidatos a investigar (ninguno confirmado, requieren verificacion del arquitecto antes de definir el fix):
- Timing de rehidratacion del plugin de persistencia de Pinia vs. el momento en que corre el guard/watchEffect.
- Fetch redundante en `useUserVets()` con el mismo queryKey que el guard, pisando el estado ya seteado.
- Algo que limpia `tenantPermissions` despues de haber sido seteado correctamente (por ejemplo un `watchEffect` que reacciona a un cambio posterior de `guid` o de query y vuelve a vaciar el estado antes de repoblarlo).

La solucion debe garantizar que el sidebar tenant nunca se renderice con `tenantPermissions` en un estado transitorio/vacio de forma persistente, sin introducir un loading bloqueante permanente que degrade la UX en la navegacion normal (cambio de ruta dentro del mismo tenant ya resuelto).

### A definir 2: Punto de gateo del sidebar
Definir si el fix correcto es gatear tambien el render de `AppSidebar`/`VetMenu` por el `isLoading` de `useVetTenant()` (igual que el `RouterView`), o si la causa raiz esta en el timing del `watchEffect`/store y el gateo actual del `RouterView` ya es suficiente una vez corregido eso. Ambas partes del sintoma (pagina carga bien, sidebar no) deben quedar explicadas por la misma causa raiz antes de implementar.

## Restricciones
- No modificar el contrato de permisos que expone el backend (Resource/roles). El fix es de sincronizacion de estado en frontend.
- No introducir un loading global que bloquee toda la navegacion tenant de forma permanente; la navegacion entre rutas del mismo tenant ya funciona bien hoy y no debe degradarse.
- Mantener el uso de `guid` en rutas/queries, sin tocar ese contrato.

## Investigacion previa que el arquitecto debe hacer
1. Confirmar el orden real de ejecucion en un F5/deep-link: rehidratacion de Pinia persist -> `vetTenantGuard.ts` -> montaje de `VetTenantLayout.vue` -> `watchEffect` de `useVetTenant.ts` -> resolucion de `useVetProfile(guid)`.
2. Verificar si `useUserVets()` dispara un fetch con el mismo queryKey que el que puebla `vetStore.userVets` en el guard, y si hay una condicion de carrera entre ambos.
3. Revisar si algun `watch`/`watchEffect` en `useVetTenant.ts` o en el store vacia `tenantPermissions` despues de haberlo seteado (por ejemplo al reaccionar a cambios de ruta/params antes de que el nuevo valor este listo).
4. Reproducir el bug con logging temporal o Vue devtools para confirmar el estado real de `tenantPermissions` en el momento exacto en que se pinta `VetMenu`.
5. Evaluar si el fix requiere gatear el render del sidebar por `isLoading`, mover el `watchEffect` a un punto mas temprano (guard o store), o ambos.

## Output esperado
Plan en `.claude/docs/plans/TKT-005-sidebar-tenant-pierde-menu-en-refresh-plan.md`
