# Plan técnico: TKT-002 - Aceptar invitación de usuario

## Input procesado
`.claude/docs/tickets/TKT-002-aceptar-invitacion-usuario.md`

---

## Resumen ejecutivo

Se implementa el flujo completo de aceptación de invitación para usuarios nuevos (vet-staff y client-owner) que reciben un email con link `/invitacion?token=...&email=...`. El trabajo se divide en dos partes: (1) un endpoint backend `POST /v1/auth/invitation/accept` que valida el token, setea el password, verifica el email y retorna un token Sanctum + datos del usuario en el mismo shape que login; (2) una página frontend `InvitationPage.vue` en el módulo `auth` existente que lee los query params, muestra el formulario, maneja los dos tipos de error diferenciados y redirige al dashboard de vet tras el éxito.

El plan NO requiere nuevas migrations ni nuevos repositorios: toda la infraestructura de datos ya existe. Requiere un nuevo Service, un nuevo FormRequest, un nuevo método en `AuthController` y tres archivos frontend nuevos (page, api function, validator export).

---

## Decisiones tomadas

### DEC-01 — Mecanismo de diferenciación token inválido vs expirado (A-definir-1)

**Decisión:** usar mensajes de error diferenciados en el campo `token` del array `errors` del 422. Token inválido lanza `ValidationException::withMessages(['token' => ['El link de invitación es inválido.']])`. Token expirado lanza `ValidationException::withMessages(['token' => ['TOKEN_EXPIRED']])` — un string constante que el frontend detecta literalmente.

**Justificación:** el interceptor 422 de `http.ts` en el frontend propaga `errors` pero NO propaga `error_code` (líneas 88-93 de `http.ts` — el campo `error_code` del payload no se incluye en el objeto rechazado). Agregar `error_code` al interceptor requeriría modificar un archivo core compartido que puede impactar otros flujos. La alternativa más segura y de menor riesgo es usar el canal `errors.token` que ya está disponible en el frontend como `err.errors?.token?.[0]`. El valor `'TOKEN_EXPIRED'` es un centinela constante inequívoco. Si en el futuro se quiere usar `error_code`, ese cambio en `http.ts` puede hacerse en otro ticket sin afectar este flujo.

**Alternativa descartada:** agregar `error_code` al interceptor de 422 en `http.ts`. Descartada porque modifica una pieza crítica compartida por todos los endpoints 422 del sistema, con riesgo de regresiones en otros flujos, y no hay cobertura de tests completa de ese interceptor actualmente.

**Alternativa descartada:** usar HTTP 410 Gone para token expirado. Descartada porque el interceptor de `http.ts` no tiene un branch para 410, lo que resultaría en el branch genérico final del interceptor y pérdida del mensaje.

---

### DEC-02 — Atomicidad de las 4 acciones (A-definir-2)

**Decisión:** envolver las 4 acciones en `DB::transaction()`. La creación del token Sanctum (`$user->createToken(...)`) ocurre DENTRO de la transacción. Si falla, el rollback revierte también los cambios en `users` (password, email_verified_at, limpieza del token de invitación). Laravel Sanctum crea tokens en la tabla `personal_access_tokens` que participa en la misma conexión de base de datos, por lo que el rollback la cubre.

**Justificación:** el patrón `DB::transaction(callback)` ya es el usado en `AuthService::signup()`. Mantiene consistencia de estilo. El riesgo de un token Sanctum creado con password no seteado queda imposibilitado.

**Alternativa descartada:** transacción manual con `DB::beginTransaction()` / `commit()` / `rollBack()`. Funcionalmente equivalente pero más verbosa; la forma de callback es más idiomática y usada en el proyecto.

---

### DEC-03 — Shape del response y reutilización del LoginResource (A-definir-3)

**Decisión:** NO crear un `LoginResource` nuevo. Reutilizar el método privado `formatUser()` del `AuthService` extrayéndolo a un método `protected` (o moviéndolo al nuevo `InvitationService` llamando al `AuthService` directamente), y construir el array de retorno con el mismo shape que `AuthService::login()` retorna:

```php
[
    'access_token'         => $token->plainTextToken,
    'user'                 => $this->authService->formatUserPublic($user),
    'must_verify_account'  => false,
    'must_change_password' => false,
]
```

Para exponer `formatUser()` al nuevo servicio sin duplicar código, se hace el método `public` en `AuthService` renombrándolo a `formatUserPublic(User $user): array`. El `InvitationService` inyecta `AuthService` y delega ahí.

**Por qué no usar `UserResource`:** el response de login usa un array plano construido por `formatUser()`, NO usa `UserResource`. `UserResource` retorna más campos (status, profiles con tenant_name, etc.) y tiene un shape distinto. El frontend (`auth.store.ts`) espera el shape de `LoginResponse` de `auth.api.ts`, que incluye `access_token`, `user` (con id, guid, first_name, last_name, email, last_login_at), `must_verify_account`, `must_change_password`. Hay que respetar ese contrato.

**NOTA de discrepancia:** `formatUser()` incluye `'id' => $user->id` (ID interno), lo que viola la Regla 5 del dominio. Sin embargo, el `User` type en `auth.types.ts` define `id: number` y el store lo usa. Cambiar esto está fuera del alcance de este ticket (afecta login, registro y todos los flujos de auth existentes). Se documenta como deuda técnica en Riesgos.

**Alternativa descartada:** crear un `InvitationResource extends JsonResource`. Agrega una abstracción sin beneficio — el shape ya está definido por el contrato del store de frontend y es un array simple sin relaciones polimórficas.

---

### DEC-04 — Ubicación de InvitationPage.vue (A-definir-4)

**Decisión:** `InvitationPage.vue` vive en `front/src/modules/auth/pages/`, junto a `LoginPage.vue`, `RegisterPage.vue`, `ForgotPasswordPage.vue`, etc.

**Justificación:** la convención del proyecto es feature modules. El flujo de invitación es parte del dominio de autenticación/onboarding, no de vets ni de clients. Todas las páginas sin sesión viven en `auth/pages/`. No se justifica un subdirectorio nuevo.

**Alternativa descartada:** crear `front/src/modules/invitations/` como módulo propio. Innecesario para una sola página; fragmenta el módulo de auth sin ganancia.

---

### DEC-05 — La API call de invitación va en `auth.api.ts`, no en un archivo separado

**Decisión:** agregar `acceptInvitationApi()` directamente en `front/src/modules/auth/api/auth.api.ts`.

**Justificación:** el archivo ya contiene todas las funciones de auth pública (login, register, forgot-password, verify-account). Agregar una función más es consistente. El ticket menciona `invitation.api.ts` como posibilidad pero la convención del proyecto es un único `auth.api.ts` para el módulo.

**Alternativa descartada:** crear `front/src/modules/auth/api/invitation.api.ts`. Fragmenta el módulo sin justificación de tamaño.

---

### DEC-06 — No crear un `InvitationService` separado: lógica en `AuthService`

**Decisión:** el método `acceptInvitation(array $data): array` vive en `AuthService`, NO en un servicio nuevo.

**Justificación:** `AuthService` ya maneja todos los flujos de autenticación sin sesión (signup, login, verifyCode). `acceptInvitation` es conceptualmente el cierre del flujo de onboarding. Crear un `InvitationService` que inyecte `AuthService` para reutilizar `formatUser` genera una dependencia circular de servicios innecesaria. Si el servicio crece, se puede extraer en otro ticket.

**Alternativa descartada:** `InvitationService` separado con inyección de `AuthService`. Crea acoplamiento entre servicios al nivel de instancias, más complejidad de binding en `AppServiceProvider`.

---

### DEC-07 — No crear composable `useInvitation.ts`; lógica directamente en la página

**Decisión:** la lógica del formulario vive directamente en `InvitationPage.vue` (script setup), sin composable extraído.

**Justificación:** el flujo es simple — un único submit, sin pasos múltiples, sin estado compartido entre componentes. Las otras páginas complejas del módulo (`ForgotPasswordPage.vue`, `LoginPage.vue`) también manejan su lógica directamente en el componente. Solo `useLogin.ts` y `useForgotPassword.ts` existen como composables porque son reutilizados o tienen estado multi-paso.

---

### DEC-08 — Throttle del endpoint

**Decisión:** aplicar `throttle:5,1` al endpoint `POST /v1/auth/invitation/accept`.

**Justificación:** consistente con `register` y `forgot-password`. Previene fuerza bruta de tokens de invitación.

---

### DEC-09 — Post-login redirect: usar `fetchUserVets()` en lugar de parsear `profiles` del response

**Decisión:** tras el éxito del endpoint, guardar token + user en el store y llamar a `fetchUserVets()` para determinar la vet destino, reutilizando exactamente la misma función que usa `LoginPage.vue` en `resolvePostLoginRoute()`.

**Justificación:** el response de `acceptInvitation` retorna el mismo shape que login, que incluye `user` sin `profiles` cargados (el `formatUser()` carga `roles` y `permissions` pero NO `profiles`). Agregar profiles al response requeriría modificar `formatUser()` afectando el contrato de login. La función `fetchUserVets()` ya existe, está testeada implícitamente por el flujo de login, y devuelve exactamente los datos necesarios para el redirect. El DEC-NEG-08 dice "Leer los profiles del usuario retornados" pero como profiles no están en el response actual de login (confirmado por código), la alternativa técnicamente correcta es usar `fetchUserVets()`.

**Impacto en DEC-NEG-08:** la lógica se mantiene fiel al espíritu del ticket (redirigir a la primera vet), solo cambia el mecanismo de obtener esa vet. Sin segunda llamada a `/v1/auth/profile` — se hace la llamada a `/v1/user/vets` que ya se hace en el login normal.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Http/Requests/AcceptInvitationRequest.php`
**Propósito:** validar los campos del endpoint `POST /v1/auth/invitation/accept`.

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'string', 'email'],
            'password'              => [
                'required',
                'string',
                'between:8,12',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[!@#$%&]/',
            ],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required'         => 'El token de invitación es requerido.',
            'email.required'         => 'El email es requerido.',
            'email.email'            => 'El email no tiene un formato válido.',
            'password.required'      => 'La contraseña es requerida.',
            'password.between'       => 'La contraseña debe tener entre 8 y 12 caracteres.',
            'password.confirmed'     => 'Las contraseñas no coinciden.',
            'password.regex'         => 'La contraseña debe contener al menos una mayúscula, un número y un símbolo (!@#$%&).',
            'password_confirmation.required' => 'La confirmación de contraseña es requerida.',
        ];
    }
}
```

**Dependencias inyectadas:** ninguna (FormRequest estándar).

---

### Archivos a modificar

#### `back/app/Services/AuthService.php`

**Cambio 1:** hacer `formatUser()` public (renombrar a `formatUserPublic`) para que sea accesible desde el controller sin romper la encapsulación del service.

**Antes:**
```php
private function formatUser(User $user): array
```

**Después:**
```php
public function formatUserPublic(User $user): array
```

Actualizar las 2 llamadas internas a `$this->formatUser(...)` → `$this->formatUserPublic(...)` dentro de `login()`.

---

**Cambio 2:** agregar método `acceptInvitation(array $data): array` al final de la clase pública, antes de los métodos privados.

```php
/**
 * Valida el token de invitación, setea la contraseña, verifica el email
 * y retorna un token Sanctum + datos del usuario (mismo shape que login).
 *
 * @throws ValidationException
 * @return array{access_token: string, user: array, must_verify_account: bool, must_change_password: bool}
 */
public function acceptInvitation(array $data): array
{
    // 1. Buscar usuario por email (mensaje genérico para no revelar existencia)
    $user = $this->userRepository->findByEmail($data['email']);

    if (! $user) {
        throw ValidationException::withMessages([
            'token' => ['El link de invitación es inválido.'],
        ]);
    }

    // 2. Verificar que el token coincide
    if ($user->verification_link_token !== $data['token']) {
        throw ValidationException::withMessages([
            'token' => ['El link de invitación es inválido.'],
        ]);
    }

    // 3. Verificar expiración
    if (
        $user->verification_link_expires_at === null
        || now()->isAfter($user->verification_link_expires_at)
    ) {
        throw ValidationException::withMessages([
            'token' => ['TOKEN_EXPIRED'],
        ]);
    }

    // 4. Ejecutar las 4 acciones atómicamente
    $sanctumToken = DB::transaction(function () use ($user, $data) {
        // 4a. Setear password hasheado
        $user->password = Hash::make($data['password']);

        // 4b. Verificar email
        $user->email_verified_at = now();

        // 4c. Limpiar token de invitación
        $user->verification_link_token        = null;
        $user->verification_link_expires_at   = null;

        // 4d. Resetear campos de autenticación
        $user->failed_login_attempts = 0;
        $user->locked_at             = null;
        $user->last_login_at         = now();
        $user->password_changed_at   = now();

        $user->save();

        // 4e. Crear token Sanctum (dentro de la transacción)
        $expirationMinutes = (int) config('sanctum.expiration', 1440) ?: 1440;
        return $user->createToken('api-access', ['*'], now()->addMinutes($expirationMinutes));
    });

    return [
        'access_token'         => $sanctumToken->plainTextToken,
        'user'                 => $this->formatUserPublic($user),
        'must_verify_account'  => false,
        'must_change_password' => false,
    ];
}
```

**Dependencias ya inyectadas en el constructor:** `UserRepositoryEloquent` (para `findByEmail`). `DB`, `Hash` ya están importados en el archivo.

**Nota sobre password history:** el método `acceptInvitation` usa `Hash::make()` directamente en lugar de `$this->userRepository->updatePassword()`. Esto es intencional porque `updatePassword()` verifica historial de passwords previos, lo que no aplica para un usuario que está seteando su password por primera vez. Si en el futuro se quiere aplicar historial aquí también, se puede refactorizar. Se documenta en Riesgos.

---

#### `back/app/Http/Controllers/V1/AuthController.php`

**Cambio:** agregar el import de `AcceptInvitationRequest` y el método `acceptInvitation`.

**Antes (imports):**
```php
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResendVerificationCodeRequest;
use App\Http\Requests\VerifyCodeRequest;
```

**Después (agregar al bloque de imports):**
```php
use App\Http\Requests\AcceptInvitationRequest;
```

**Agregar método al final de la clase:**
```php
public function acceptInvitation(AcceptInvitationRequest $request): JsonResponse
{
    try {
        $result = $this->authService->acceptInvitation($request->validated());
        return $this->makeSuccess($result, 'Bienvenido. Tu cuenta fue activada correctamente.');
    } catch (\Exception $e) {
        return $this->makeFromException($e);
    }
}
```

---

#### `back/routes/api/auth.php`

**Cambio:** agregar la ruta del nuevo endpoint.

**Antes (último bloque antes del cierre del grupo principal):**
```php
    Route::prefix('forgot-password')->middleware('throttle:5,1')->group(function () {
        // ...
    });
});
```

**Después (agregar antes del cierre `});` del grupo `v1/auth`):**
```php
    Route::post('invitation/accept', [AuthController::class, 'acceptInvitation'])
        ->middleware('throttle:5,1');
```

**Ruta completa resultante:** `POST /v1/auth/invitation/accept`
**Middleware:** `throttle:5,1` (sin `auth:sanctum`, sin `vet.tenant`).

---

### Migrations
No se requieren migrations nuevas. Los campos `verification_link_token` y `verification_link_expires_at` ya existen en la tabla `users`.

### Permisos Spatie
No aplica. Este endpoint es público (sin autenticación previa).

---

### Contrato del endpoint

**Request:** `POST /v1/auth/invitation/accept`
```json
{
    "token": "string (requerido, 64 chars aleatorios)",
    "email": "string (requerido, formato email)",
    "password": "string (requerido, 8-12 chars, 1 mayúscula, 1 número, 1 símbolo !@#$%&)",
    "password_confirmation": "string (requerido, debe coincidir con password)"
}
```

**Response 200 (éxito):**
```json
{
    "success": true,
    "data": {
        "access_token": "1|AbCdEfGhIj...",
        "user": {
            "id": 42,
            "guid": "a1b2c3d4-...",
            "first_name": "Juan",
            "last_name": "Pérez",
            "email": "juan@clinica.com",
            "last_login_at": "2026-06-17T10:00:00.000000Z",
            "roles": [
                { "guid": "...", "name": "vet-assistant" }
            ],
            "permissions": ["vets.lectura"]
        },
        "must_verify_account": false,
        "must_change_password": false
    },
    "message": "Bienvenido. Tu cuenta fue activada correctamente."
}
```

**Errores posibles:**

| HTTP | Campo `errors` | Valor en `errors.token[0]` | Cuándo |
|------|----------------|---------------------------|--------|
| 422  | `{ token: [...] }` | `"El link de invitación es inválido."` | Email no encontrado o token no coincide |
| 422  | `{ token: [...] }` | `"TOKEN_EXPIRED"` | Token expiró (comparado contra `now()`) |
| 422  | `{ password: [...], ... }` | (mensajes de validación) | Password no cumple reglas |
| 429  | — | — | Rate limit superado |
| 500  | — | — | Error inesperado del servidor |

**Nota sobre el 422:** el interceptor de `http.ts` en el frontend rechaza la promesa con `{ success: false, message: "...", errors: { token: ["TOKEN_EXPIRED"] } }`. El frontend lee `err.errors?.token?.[0] === 'TOKEN_EXPIRED'` para diferenciar el caso.

---

### Tests a generar (backend)

**Feature tests para `POST /v1/auth/invitation/accept`:**

1. **Happy path — vet-staff:** usuario con perfil vet-staff, token válido, password válido → 200 con `access_token`, `user.guid` correcto, `must_verify_account: false`.
2. **Happy path — client-owner:** ídem con perfil client-owner.
3. **Token inválido (email no existe):** email inexistente → 422, `errors.token[0]` contiene "inválido".
4. **Token inválido (token no coincide):** email existe pero token no coincide → 422, `errors.token[0]` contiene "inválido".
5. **Token expirado:** `verification_link_expires_at` en el pasado → 422, `errors.token[0] === 'TOKEN_EXPIRED'`.
6. **Password sin mayúscula:** → 422, `errors.password` presente.
7. **Password sin número:** → 422.
8. **Password sin símbolo:** → 422.
9. **Passwords no coinciden:** → 422.
10. **Password demasiado corto (< 8):** → 422.
11. **Idempotencia — token ya usado:** tras un accept exitoso, el token queda null; una segunda llamada con el mismo token → 422 "inválido".
12. **Atomicidad (mock de fallo Sanctum):** si `createToken` lanza excepción, la transacción hace rollback y el usuario no queda con password seteado ni `email_verified_at` seteado.
13. **Rate limiting:** 6 requests en menos de 1 minuto → el 6to devuelve 429.
14. **Usuario ya verificado (edge case):** si el usuario ya tiene `email_verified_at` seteado y token en null → 422 "inválido" (cubre el caso de reenvío sin flujo de reenvío).

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/auth/pages/InvitationPage.vue`

**Propósito:** página de aceptación de invitación. Lee `token` y `email` de query params, muestra email como texto informativo, formulario de password + confirmación, manejo diferenciado de errores.

**Estructura completa:**

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import { resetPasswordSchema } from '@/modules/auth/validators/auth.validator';
import { useAuthStore } from '@/modules/auth/stores/auth.store';
import { useVetStore } from '@/core/stores/vet.store';
import { fetchUserVets } from '@/modules/vets/api/user-vets.api';
import { useNotification } from '@/core/composables/useNotification';
import { acceptInvitationApi } from '@/modules/auth/api/auth.api';
import { LockOutlined } from '@ant-design/icons-vue';
import AuthFormField from '@/modules/auth/components/AuthFormField.vue';
import AuthServerError from '@/modules/auth/components/AuthServerError.vue';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const vetStore = useVetStore();
const { error: notifyError } = useNotification();

// Query params leídos al montar
const tokenParam = ref<string>('');
const emailParam = ref<string>('');

const serverError = ref<string | null>(null);
const isSubmitting = ref(false);
const isExpiredError = ref(false);

const { handleSubmit, errors, defineField } = useForm({
    validationSchema: toTypedSchema(resetPasswordSchema),
});

const [password, passwordAttrs] = defineField('password');
const [passwordConfirmation, passwordConfirmationAttrs] = defineField('password_confirmation');

onMounted(() => {
    const token = route.query.token as string | undefined;
    const email = route.query.email as string | undefined;

    if (!token || !email) {
        notifyError('El link de invitación es inválido. Pedile al administrador que reenvíe la invitación.');
        router.replace('/login');
        return;
    }

    tokenParam.value = token;
    emailParam.value = email;
});

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null;
    isExpiredError.value = false;
    isSubmitting.value = true;

    try {
        const result = await acceptInvitationApi({
            token: tokenParam.value,
            email: emailParam.value,
            password: values.password,
            password_confirmation: values.password_confirmation,
        });

        // Poblar el store (idéntico al flujo de login)
        authStore.token = result.access_token;
        authStore.user = result.user;
        authStore.mustVerifyAccount = false;
        authStore.mustChangePassword = false;

        // Determinar destino: buscar primera vet del usuario
        try {
            const vets = await fetchUserVets();
            if (vets.length > 0) {
                vetStore.setUserVets(vets);
                const target = vets[0].slug;
                router.push(`/vets/${target}/perfil`);
            } else {
                // Sin vets (client-owner u otro rol sin panel vet)
                notifyError('Tu cuenta fue activada. El panel para tu tipo de perfil estará disponible próximamente.');
                authStore.$reset();
                router.push('/login');
            }
        } catch {
            // fetchUserVets falló — ir al dashboard genérico
            router.push('/dashboard');
        }
    } catch (err: unknown) {
        const e = err as { message?: string; errors?: Record<string, string[]> };
        const tokenError = e.errors?.token?.[0];

        if (tokenError === 'TOKEN_EXPIRED') {
            isExpiredError.value = true;
            serverError.value = null;
        } else if (tokenError) {
            serverError.value = 'El link de invitación es inválido. Pedile al administrador que reenvíe la invitación.';
        } else {
            serverError.value = e.message ?? 'Ocurrió un error inesperado. Intentá nuevamente.';
        }
    } finally {
        isSubmitting.value = false;
    }
});
</script>

<template>
    <div>
        <h1 class="auth-form-title">Activar cuenta</h1>
        <p class="auth-form-subtitle">
            Creá tu contraseña para acceder al sistema como
            <strong style="color: var(--auth-accent)">{{ emailParam }}</strong>
        </p>

        <!-- Error de token expirado -->
        <a-alert
            v-if="isExpiredError"
            type="warning"
            show-icon
            style="margin-bottom: 20px"
        >
            <template #message>
                Tu invitación expiró. Pedile al administrador que reenvíe la invitación.
            </template>
        </a-alert>

        <!-- Otros errores de servidor -->
        <AuthServerError :message="serverError" />

        <form v-if="!isExpiredError" @submit.prevent="onSubmit">
            <AuthFormField label="Contraseña" :error="errors.password">
                <a-input-password
                    v-model:value="password"
                    v-bind="passwordAttrs"
                    autocomplete="new-password"
                    placeholder="8-12 chars, mayúscula, número y símbolo"
                    size="large"
                >
                    <template #prefix>
                        <LockOutlined />
                    </template>
                </a-input-password>
            </AuthFormField>

            <AuthFormField label="Confirmar contraseña" :error="errors.password_confirmation">
                <a-input-password
                    v-model:value="passwordConfirmation"
                    v-bind="passwordConfirmationAttrs"
                    autocomplete="new-password"
                    placeholder="Repetí tu contraseña"
                    size="large"
                >
                    <template #prefix>
                        <LockOutlined />
                    </template>
                </a-input-password>
            </AuthFormField>

            <a-button
                type="primary"
                html-type="submit"
                block
                size="large"
                :loading="isSubmitting"
            >
                Activar cuenta
            </a-button>
        </form>

        <p class="auth-footer-text" style="margin-top: 20px">
            <RouterLink to="/login" class="auth-link">← Volver al inicio de sesión</RouterLink>
        </p>
    </div>
</template>
```

**Notas de implementación de la página:**
- `resetPasswordSchema` de `auth.validator.ts` ya existe y tiene exactamente la validación requerida (password 8-12, mayúscula, número, símbolo, confirmación). Se reutiliza sin cambios.
- El formulario se oculta (`v-if="!isExpiredError"`) cuando el token expiró, mostrando solo el alert. Esto evita que el usuario pierda tiempo completando el form cuando la única acción posible es contactar al admin.
- `authStore.token`, `authStore.user`, etc. se setean directamente porque el store no tiene un método público de "setSession" — se sigue el mismo patrón que usa el store internamente en `login()`.
- `vetStore.setUserVets(vets)` — confirmar que `useVetStore` tiene este método (visible en `LoginPage.vue` línea 31).

---

### Archivos a modificar

#### `front/src/modules/auth/api/auth.api.ts`

**Cambio:** agregar la interfaz `AcceptInvitationPayload`, la interfaz `AcceptInvitationResponse` y la función `acceptInvitationApi`.

**Agregar al bloque de tipos (después de `ForgotPasswordResetPasswordPayload`):**
```typescript
export interface AcceptInvitationPayload {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export interface AcceptInvitationUserResponse {
    id: number;
    guid: string;
    first_name: string;
    last_name: string;
    email: string;
    last_login_at: string | null;
    roles?: { guid: string; name: string }[];
    permissions?: string[];
}

export interface AcceptInvitationResponse {
    access_token: string;
    user: AcceptInvitationUserResponse;
    must_verify_account: boolean;
    must_change_password: boolean;
}
```

**Agregar al bloque de funciones (al final del archivo):**
```typescript
export async function acceptInvitationApi(payload: AcceptInvitationPayload): Promise<AcceptInvitationResponse> {
    const response = await http.post<AcceptInvitationResponse>('/v1/auth/invitation/accept', payload);
    return response.data;
}
```

---

#### `front/src/modules/auth/router/auth.routes.ts`

**Cambio:** agregar la ruta `/invitacion` al array `authRoutes`.

**Antes:**
```typescript
export const authRoutes: RouteRecordRaw[] = [
    // ...rutas existentes...
    {
        path: '/reset-password/:guid',
        name: 'reset-password',
        component: () => import('@/modules/auth/pages/ResetPasswordPage.vue'),
        meta: { requiresGuest: true },
    },
]
```

**Después (agregar al final del array):**
```typescript
    {
        path: '/invitacion',
        name: 'invitation',
        component: () => import('@/modules/auth/pages/InvitationPage.vue'),
        meta: { requiresGuest: true },
    },
```

**Por qué funciona `requiresGuest: true`:** el router en `index.ts` (líneas 65-69) ejecuta `guestGuard` para cualquier ruta con `meta.requiresGuest`. `guestGuard` verifica `auth.isAuthenticated` y redirige al dashboard si el usuario ya tiene sesión. No se requiere ningún guard adicional ni modificación del router principal.

**Nota sobre el layout:** las `authRoutes` están declaradas como hijas del bloque con `path: '/auth'` y `component: AuthLayout`. Sin embargo, los paths en `auth.routes.ts` son todos absolutos (empiezan con `/`), lo que en Vue Router hace que el path del hijo sea absoluto y REEMPLACE al del padre. Por tanto, `/invitacion` usa `AuthLayout` correctamente (porque es hijo de ese bloque en el árbol) y la URL resultante es `/invitacion`, no `/auth/invitacion`. Este comportamiento es consistente con todas las rutas auth existentes (`/login`, `/register`, etc.).

---

### Tipos TypeScript

No se crea ningún archivo nuevo de tipos. Los tipos necesarios se agregan directamente en `auth.api.ts` como se muestra arriba. El tipo `User` de `auth.types.ts` NO se modifica (las interfaces en `auth.api.ts` son específicas del contrato de API y se mantienen separadas del tipo de store).

---

### Tests a generar (frontend)

1. **Redirect si token ausente:** montar `InvitationPage.vue` sin query params → verifica que `router.replace('/login')` fue llamado y `notifyError` fue llamado con el mensaje correcto.
2. **Redirect si email ausente:** idem con solo `token` presente.
3. **Render con params válidos:** montar con `?token=abc&email=juan@test.com` → el email se muestra como texto, el formulario es visible.
4. **Submit exitoso con vet:** mock de `acceptInvitationApi` que resuelve + mock de `fetchUserVets` que devuelve `[{ slug: 'clinica-norte', ... }]` → verifica que `authStore.token` se seteó, `router.push('/vets/clinica-norte/perfil')` fue llamado.
5. **Submit exitoso sin vets (client-owner):** mock de `fetchUserVets` que devuelve `[]` → verifica `router.push('/login')` y `authStore.$reset()`.
6. **Error token expirado:** mock que rechaza con `{ errors: { token: ['TOKEN_EXPIRED'] } }` → `isExpiredError` es true, formulario oculto, alert de expiración visible.
7. **Error token inválido:** mock que rechaza con `{ errors: { token: ['El link de invitación es inválido.'] } }` → `serverError` visible con mensaje de inválido.
8. **Error 500:** mock que rechaza con `{ message: 'Error interno' }` → `serverError` visible con el mensaje.
9. **Validación Zod password corto:** submit con password de 5 chars → error inline, sin llamada a `acceptInvitationApi`.
10. **Validación Zod passwords no coinciden:** → error inline en `password_confirmation`.

---

## Orden de implementación

1. **Modificar `back/app/Services/AuthService.php`:** renombrar `formatUser` a `formatUserPublic` (visibilidad `public`), actualizar las 2 llamadas internas, y agregar el método `acceptInvitation(array $data): array` con la lógica de transacción.

2. **Crear `back/app/Http/Requests/AcceptInvitationRequest.php`:** con las reglas de validación exactas descritas arriba.

3. **Modificar `back/app/Http/Controllers/V1/AuthController.php`:** agregar import de `AcceptInvitationRequest` y el método `acceptInvitation(AcceptInvitationRequest $request): JsonResponse`.

4. **Modificar `back/routes/api/auth.php`:** agregar la ruta `Route::post('invitation/accept', ...)` con `throttle:5,1`.

5. **Verificar manualmente con curl/Postman:** `POST /v1/auth/invitation/accept` con un usuario de test que tenga token válido → confirmar response shape, confirmar que el token queda null en DB tras el accept.

6. **Modificar `front/src/modules/auth/api/auth.api.ts`:** agregar `AcceptInvitationPayload`, `AcceptInvitationUserResponse`, `AcceptInvitationResponse` y `acceptInvitationApi()` al final del archivo.

7. **Modificar `front/src/modules/auth/router/auth.routes.ts`:** agregar la ruta `/invitacion` con `meta: { requiresGuest: true }`.

8. **Crear `front/src/modules/auth/pages/InvitationPage.vue`:** implementar según el código completo del plan.

9. **Verificar flujo E2E manual:** usar un link de invitación real → confirmar redirect a `/vets/{slug}/perfil` para vet-staff, confirmar mensaje de error para client-owner.

10. **Escribir feature tests backend** cubriendo los 14 casos listados.

11. **Escribir tests frontend** cubriendo los 10 casos listados.

---

## Riesgos y consideraciones

### R-01 — `formatUser()` incluye `id` interno (violación Regla 5)
El método `formatUser()` retorna `'id' => $user->id`. Esto viola la regla "GUID como identificador, nunca ID interno". El store de frontend tiene `id: number` en el tipo `User`. Modificar esto afecta el contrato de login, register y todos los flujos de auth existentes — está fuera del alcance de este ticket. Se introduce como deuda técnica documentada. El nuevo endpoint hereda este problema al reutilizar `formatUserPublic`.

### R-02 — `createToken` dentro de `DB::transaction()`: comportamiento con SQLite en tests
En producción (MySQL), `personal_access_tokens` participa en la misma conexión y el rollback la cubre. En tests con SQLite in-memory, el comportamiento es idéntico. Sin embargo, si algún test usa múltiples conexiones de base de datos, puede haber un edge case. Asegurarse de que los feature tests usen `RefreshDatabase` trait.

### R-03 — `password_changed_at` seteado en `acceptInvitation` vs comportamiento de historial
El método `acceptInvitation` no usa `userRepository->updatePassword()` (que verifica historial), sino que setea el password directamente. Si en el futuro se activa `password_history_count` en system_settings, el primer password del usuario nunca se guardará en historial. Esto es aceptable para el caso de onboarding (no tiene sentido pedirle al usuario que no repita un password que nunca tuvo), pero conviene documentarlo.

### R-04 — `AcceptInvitationUserResponse` duplica campos de `UserApiResponse` y `User`
Hay tres interfaces con campos similares en el módulo auth: `UserApiResponse` (auth.api.ts), `User` (auth.types.ts), y la nueva `AcceptInvitationUserResponse`. La proliferación de tipos similares es deuda técnica existente en el módulo. En este ticket se agrega una más porque el response no tiene exactamente el mismo shape que `UserApiResponse` (los campos `roles` y `permissions` son opcionales en `UserApiResponse` pero el response de invitación los incluye siempre). La consolidación de tipos de auth es un refactor que debe hacerse en un ticket dedicado.

### R-05 — `vetStore.setUserVets()` debe existir
`InvitationPage.vue` llama a `vetStore.setUserVets(vets)`. Esto se infiere del código de `LoginPage.vue` (línea 31). Si el método no existe o tiene otro nombre, la página fallará en tiempo de ejecución. El dev debe verificar la firma de `useVetStore` antes de completar el Paso 8.

### R-06 — El interceptor 422 de `http.ts` no propaga `error_code`
El campo `error_code` del payload de error es leído correctamente en el interceptor de la respuesta EXITOSA (línea 50-55 del interceptor de response — para el caso `success: false` en 2xx). Pero en el branch de `status === 422` del interceptor de error (líneas 88-93), NO se propaga `error_code`. Por eso la decisión DEC-01 usa `errors.token[0]` como centinela. Si en otro ticket se quiere estandarizar el uso de `error_code`, se debe modificar `http.ts` agregando `error_code: payload?.['error_code'] ?? null` al objeto rechazado del branch 422.

### R-07 — Multi-tenant (DEC-NEG-11 aplicado correctamente)
Confirmado: el endpoint NO filtra por tenant, lo cual es correcto según DEC-NEG-11. El usuario aún no tiene sesión y su perfil de tenant ya fue asignado cuando fue invitado. No hay riesgo de cross-tenant aquí.

### R-08 — Multi-país
No aplica a este ticket. El flujo de invitación no toca campos de regulación por país (RENSPA, CUIT, etc.).

### R-09 — Edge case: usuario invitado con `email_verified_at` ya seteado
Si por alguna razón el usuario tiene `email_verified_at != null` pero `verification_link_token != null` (caso improbable, podría ocurrir con datos inconsistentes), el endpoint permite la invitación igual (no verifica si ya está verificado). Este es comportamiento correcto — si tiene token y no expiró, se le permite completar la invitación.

### R-10 — `useVetStore` import en `InvitationPage.vue`
El path `@/core/stores/vet.store` se infiere de `LoginPage.vue`. Si el archivo se movió o tiene otro nombre, habrá un error de import. Verificar antes del Paso 8.

---

## Pendientes / fuera de alcance

- **Reenvío de invitación desde el frontend:** el ticket explícitamente excluye el flujo de reenvío. Si el token expiró, la instrucción al usuario es contactar al administrador. No hay botón de reenvío en `InvitationPage.vue`.
- **Panel de client-owner:** cuando un client-owner acepta la invitación y no tiene vets, se redirige a `/login` con un mensaje. El panel de clientes no existe aún (limitación temporal documentada en el ticket).
- **Consolidación de tipos de auth en frontend:** las interfaces `UserApiResponse`, `User` y `AcceptInvitationUserResponse` tienen campos solapados. Un refactor de tipos es necesario pero fuera del alcance.
- **Propagación de `error_code` en el interceptor 422 de `http.ts`:** puede ser conveniente para estandarizar manejo de errores en el futuro. Ticket separado.
- **Guardar `password_changed_at` en historial para el primer password:** ver R-03. Fuera de alcance.
- **Test de rate limiting en feature tests:** puede requerir ajuste del config `throttle` en el entorno de test. Si causa problemas, el test de rate limiting puede marcarse como pending.
