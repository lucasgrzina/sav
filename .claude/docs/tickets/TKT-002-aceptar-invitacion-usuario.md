# TKT-002 - Aceptar invitación de usuario: endpoint + página frontend

## Tipo
Bug fix + Feature (flujo completo faltante)

## Contexto
El sistema SAV invita a nuevos usuarios (vet-staff y client-owners) enviando un email con un link del tipo `http://host/invitacion?token=...&email=...`. Ese link no lleva a ningún lado: no existe el endpoint backend para validar el token y asignar contraseña, ni existe la ruta `/invitacion` en el frontend. El usuario recibe un email de bienvenida que lo deja en un callejón sin salida, bloqueando por completo el onboarding de cualquier usuario nuevo que no sea el vet principal.

## Estado actual

**Backend:**
- La tabla `users` tiene los campos `verification_link_token` (string 64, nullable) y `verification_link_expires_at` (datetime, nullable).
- Los jobs `SendVetStaffInvitationJob` y `SendClientOwnerInvitationJob` crean el usuario con password temporal aleatoria y token de 72 hs, y envían el email con el link.
- No existe ningún endpoint en `/v1/auth/` para consumir ese token.

**Frontend:**
- Rutas auth existentes: `/login`, `/register`, `/verify-account/:guid`, `/forgot-password`, `/reset-password/:guid`.
- No existe la ruta `/invitacion` ni ninguna página asociada.

El link generado en los emails llega con los query params `token` y `email`. Actualmente el navegador muestra la página 404 del frontend (o redirige al login sin ningún mensaje).

---

## Decisiones tomadas (no negociables)

### DEC-NEG-01 — Endpoint: `POST /v1/auth/invitation/accept`
El nuevo endpoint vive bajo el prefijo `/v1/auth/` junto a los demás flujos de autenticación (forgot-password, verify-account). No requiere autenticación previa (el usuario aún no tiene sesión). Ruta completa: `POST /v1/auth/invitation/accept`.

### DEC-NEG-02 — Input del endpoint
```json
{
  "token": "string (requerido)",
  "email": "string (requerido, formato email)",
  "password": "string (requerido)",
  "password_confirmation": "string (requerido)"
}
```
La validación de password sigue el mismo schema que `POST /v1/auth/forgot-password/reset-password`.

### DEC-NEG-03 — Lógica de validación del token
Orden de validaciones:
1. Buscar user por `email`. Si no existe → 422 con mensaje genérico (no revelar si el email existe).
2. Verificar que `verification_link_token` coincide con el `token` recibido. Si no coincide → 422.
3. Verificar que `verification_link_expires_at` no expiró (comparar contra `now()`). Si expiró → 422 con mensaje específico de token expirado.

### DEC-NEG-04 — Acciones al aceptar la invitación
Al validar correctamente el token, en una transacción atómica:
1. Setear el password hasheado del usuario.
2. Setear `email_verified_at = now()`.
3. Limpiar `verification_link_token = null` y `verification_link_expires_at = null`.
4. Crear y retornar un token Sanctum (auto-login).

### DEC-NEG-05 — Response del endpoint
El endpoint retorna exactamente el mismo shape que `POST /v1/auth/login`: token Sanctum + datos del usuario autenticado (perfil completo). El frontend no necesita hacer una segunda llamada para obtener el perfil si el response lo incluye.

### DEC-NEG-06 — Comportamiento frontend: lectura de query params
La página `InvitationPage.vue` lee `token` y `email` de los query params de la URL al montarse. Si alguno de los dos está ausente o vacío, redirige inmediatamente a `/login` mostrando una notificación de error (toast) con el mensaje "El link de invitación es inválido. Pedile al administrador que reenvíe la invitación."

### DEC-NEG-07 — Formulario de la página
La página muestra únicamente dos campos: `password` y `password_confirmation`. El email se muestra como texto informativo (no editable) para que el usuario sepa a qué cuenta está accediendo. No hay campo de email editable.

### DEC-NEG-08 — Flujo post-submit exitoso
Al recibir respuesta exitosa del endpoint:
1. Guardar el token Sanctum en el auth store (idéntico al flujo de login).
2. Usar los datos de usuario retornados en el response para poblar el auth store (sin llamada adicional a `GET /v1/auth/profile`).
3. Leer los profiles del usuario retornados: si tiene al menos un profile de tipo `vet`, redirigir al dashboard de la primera vet encontrada (`/vets/:vetSlug/`).
4. Si no tiene ningún profile de tipo `vet` (es client-owner u otro rol sin panel vet), redirigir a `/login` con un toast informativo. Esta es una limitación temporal: el panel de clientes no existe aún.

### DEC-NEG-09 — Flujo post-submit con error
- Token inválido o email no encontrado (422): mostrar mensaje de error inline en el formulario. No redirigir.
- Token expirado (422 con código específico): mostrar mensaje "Tu invitación expiró. Pedile al administrador que reenvíe la invitación." con un link de soporte. No hay flujo de reenvío automático desde esta página.
- Error 500 inesperado: toast de error genérico.

### DEC-NEG-10 — Meta de ruta frontend
La ruta `/invitacion` debe tener `meta: { requiresGuest: true }`. Si el usuario ya está autenticado y navega a `/invitacion`, el guard existente de `requiresGuest` lo redirige al dashboard sin mostrar la página.

### DEC-NEG-11 — Scope multi-tenant y seguridad
La búsqueda del usuario en el endpoint se hace por `email` + `token`. No hay contexto de tenant en este endpoint (el usuario aún no pertenece a ninguna sesión). El tenant del usuario se deriva de su `UserProfile` ya existente, creado cuando fue invitado. El endpoint NO filtra por tenant.

---

## Decisiones que el arquitecto debe tomar

### A definir 1 — Manejo del token expirado: código de error diferenciado
El frontend necesita distinguir entre "token inválido" y "token expirado" para mostrar mensajes distintos. El arquitecto debe definir cómo diferenciar ambos casos en el response 422: puede ser via un campo `error_code` en el body, via mensajes de validación distintos en el campo `token`, o cualquier otro mecanismo consistente con los demás endpoints de auth del sistema. Lo que sea que elija debe ser consumible de forma determinista desde el frontend.

### A definir 2 — Atomicidad de las acciones al aceptar
El arquitecto debe garantizar que las 4 acciones del DEC-NEG-04 (setear password, `email_verified_at`, limpiar token, crear Sanctum token) ocurren en una transacción atómica. Si la creación del token Sanctum falla, el usuario no debe quedar con password seteado pero sin poder autenticarse.

### A definir 3 — Shape exacto del response y reutilización del LoginResource
El DEC-NEG-05 establece que el response debe ser idéntico al de login. El arquitecto debe verificar si existe un `LoginResource` o equivalente reutilizable, o si hay que construir el shape directamente en el controlador. Lo que importa es que el frontend pueda usar el mismo parser/tipo `AuthResponse` que usa para el login.

### A definir 4 — Ubicación del archivo de ruta frontend
El arquitecto debe decidir si `InvitationPage.vue` vive en el módulo `auth` existente (junto a `LoginPage`, `RegisterPage`, etc.) o si requiere un subdirectorio nuevo. Debe ser consistente con la convención de feature modules del proyecto.

---

## Restricciones

- El endpoint `POST /v1/auth/invitation/accept` NO requiere middleware `auth:sanctum` — el usuario no tiene sesión aún.
- El endpoint NO debe estar bajo el middleware `vet.tenant`.
- GUID como identificador: si en algún punto el response del endpoint retorna datos del usuario o del perfil, deben usar `guid`, nunca `id` interno.
- El token `verification_link_token` es de uso único: una vez aceptada la invitación debe quedar `null` para que no pueda reutilizarse.
- La ruta `/invitacion` debe respetar el guard `requiresGuest` ya existente en el router. No crear un guard nuevo para esto.
- No implementar reenvío de invitación en este ticket. Si el token expiró, la instrucción al usuario es contactar al administrador.
- No implementar el panel de client-owner en este ticket. El redirect a `/login` para roles sin panel vet es comportamiento aceptado temporalmente.

---

## Investigación previa que el arquitecto debe hacer

1. Verificar el shape exacto del response de `POST /v1/auth/login` para replicarlo fielmente en el nuevo endpoint. Revisar el controlador de login y el resource asociado (si existe).
2. Verificar si existe un `AuthResponse` type o schema Zod en el frontend que parsea el response de login, para reutilizarlo en el nuevo endpoint.
3. Revisar el controlador o servicio de `forgot-password/reset-password` para reutilizar la validación de password (reglas, mensajes de error) sin duplicarla.
4. Confirmar la estructura del módulo `auth` en el frontend (`front/src/modules/auth/`) para determinar dónde viven las páginas existentes y seguir la misma convención.
5. Verificar cómo el auth store guarda el token Sanctum y el perfil de usuario tras el login, para replicar exactamente esa lógica en el flujo de aceptar invitación.
6. Confirmar cómo el response de login expone los `UserProfile` del usuario (incluyendo el `type` o discriminador que permite saber si es perfil `vet` o `client`), ya que el DEC-NEG-08 depende de poder leer el primer profile de tipo `vet`.
7. Revisar el guard `requiresGuest` en el router frontend para confirmar que solo requiere agregar `meta: { requiresGuest: true }` en la definición de la ruta, sin lógica adicional.

---

## Output esperado

Plan técnico en `.claude/docs/plans/TKT-002-aceptar-invitacion-usuario-plan.md` que incluya:
- Implementación completa del endpoint `POST /v1/auth/invitation/accept`: FormRequest, Service o lógica en controlador, Resource de respuesta, registro de ruta.
- Implementación completa de `InvitationPage.vue`: lectura de query params, formulario, llamada a la API, manejo de errores diferenciados (token inválido vs expirado), flujo de redirect post-éxito.
- Archivo de API en el módulo auth frontend (`invitation.api.ts` o equivalente según convención).
- Registro de la ruta `/invitacion` en el router con `meta: { requiresGuest: true }`.
- Cómo resolver el A-definir-1 (diferenciación token inválido vs expirado en el 422).
- Cómo garantizar la atomicidad de las acciones (A-definir-2).
