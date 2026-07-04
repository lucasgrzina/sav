# Plan técnico: Nuevo proyecto base con Auth completo

## Input procesado
Input directo por chat (sin archivo de spec/ticket). Solicitud: scaffolding de nuevo proyecto Laravel 12 + Vue 3 + TypeScript con auth completo (login, registro, forgot-password, reset-password, dashboard protegido), replicando la arquitectura de Alphinance.

---

## Resumen ejecutivo

Se construye un proyecto nuevo desde cero con dos directorios raíz: `mi-proyecto-back/` (Laravel 12) y `mi-proyecto-front/` (Vue 3 + TypeScript + Vite + Tailwind). El backend implementa auth completo via Sanctum en modo token, siguiendo el patrón Service + Repository con `l5-repository` (mismo que Alphinance), incluyendo `AuthService`, `ForgotPasswordService`, repositorio de User, FormRequests, ApiResponseTrait y ResponseHelper idénticos al original. El flujo de forgot-password usa una tabla `password_resets` dedicada con código de 6 dígitos (igual que Alphinance). El frontend replica la estructura completa de Alphinance: cliente Axios con interceptors, stores Pinia, router con guards, composables, i18n en español, y vistas limpias con Tailwind para todas las pantallas de auth y un dashboard básico. No hay multi-tenancy ni permisos Spatie en esta iteración.

---

## Decisiones tomadas

**DEC-01 — Sanctum en modo token (no cookie)**
Decisión: usar Sanctum con tokens Bearer en lugar de cookies de sesión.
Justificación: el nuevo proyecto es una SPA desacoplada (frontend en dominio distinto al backend). El modo token es más simple de configurar sin CORS/cookies cross-origin. Alphinance soporta ambos modos; para un nuevo proyecto el modo token es el arranque más simple y portable.
Alternativa descartada: modo cookie. Requiere configuración de CORS con `withCredentials: true`, `SESSION_DOMAIN`, y prefetch de CSRF. Agrega complejidad sin beneficio en esta iteración.

**DEC-02 — l5-repository (prettus/l5-repository) para el Repository pattern**
Decisión: usar `prettus/l5-repository` como base, igual que Alphinance. `BaseRepositoryEloquent` extiende `Prettus\Repository\Eloquent\BaseRepository` y agrega los métodos `findByGuid`, `updateByGuid`, `newQuery`.
Justificación: el código de Alphinance usa este package extensamente. Replicarlo asegura consistencia de patrón y que el dev pueda trasladar conocimiento directamente.
Alternativa descartada: implementar el patrón Repository sin el package (solo interfaces + clases propias). Más código boilerplate, sin beneficio real para un proyecto que ya tiene el patrón definido.

**DEC-03 — Forgot-password con tabla `password_resets` propia + código 6 dígitos**
Decisión: NO usar el `PasswordBroker` nativo de Laravel. Usar una tabla `password_resets` propia con columnas `user_id`, `token`, `code`, `used`, igual al modelo `PasswordReset` de Alphinance.
Justificación: el flujo de Alphinance tiene dos pasos (verificar email → recibir código 6 dígitos → ingresar código → nueva contraseña). El PasswordBroker de Laravel usa un enlace de un solo clic. El flujo de código es más UX-friendly en mobile y el dev ya conoce este patrón.
Alternativa descartada: `Password::sendResetLink()`. Incompatible con el flujo de 2 pasos con código numérico.

**DEC-04 — Sin 2FA en esta iteración**
Decisión: no se implementa Two-Factor Authentication.
Justificación: el scope declarado es "auth básico + dashboard". El 2FA de Alphinance depende de `TwoFactorChallengeService`, modelo `TwoFactorChallenge`, y código TOTP. Es un módulo separado que se agrega en una segunda iteración.
Alternativa descartada: incluir 2FA. Agrega 8+ archivos y complejidad fuera del scope declarado.

**DEC-05 — VeeValidate + Zod para validación en frontend**
Decisión: usar VeeValidate como motor de formularios y Zod como schema de validación, según lo solicitado.
Justificación: VeeValidate v4 tiene integración nativa con Zod via `@vee-validate/zod`. Es el stack más adoptado para Vue 3 + TypeScript. Permite definir schemas tipados y reutilizarlos.
Alternativa descartada: FormKit. Mayor curva de aprendizaje, menos adopción en la comunidad Vue 3.

**DEC-06 — Verificación de email post-registro con código de 6 dígitos**
Decisión: el registro crea el usuario con `email_verified_at = null` y envía un código de 6 dígitos al email. El login detecta `must_verify_account: true` y redirige a la vista de verificación.
Justificación: Alphinance tiene este flujo exactamente y el `AuthService::signup()` ya está diseñado así. Es el patrón a replicar.
Alternativa descartada: verificación via link de un click. Menos UX en mobile, incompatible con el patrón del proyecto de referencia.

**DEC-07 — No se implementa refresh token en esta iteración**
Decisión: Sanctum emite un único access token sin expiración. No hay refresh token.
Justificación: simplifica el setup inicial. El refresh token se agrega en una segunda iteración cuando se defina la duración de sesiones. Alphinance tiene `access_token_expiration` y `refresh_token_expiration` configurables en `sanctum.php`; el nuevo proyecto puede arrancar sin esa complejidad.
Alternativa descartada: implementar refresh token desde el inicio. Agrega lógica en el interceptor de Axios y en el AuthService que no es necesaria para validar el flujo core.

**DEC-08 — Estructura de directorios idéntica a Alphinance**
Decisión: `mi-proyecto-back/` y `mi-proyecto-front/` en la raíz del repo. Mismos subdirectorios internos.
Justificación: el dev puede copiar convenciones directamente. Los nombres de directorio son los mismos del CLAUDE.md del proyecto de referencia.

**DEC-09 — Ant Design Vue + PrimeVue NO se incluyen; solo Tailwind CSS**
Decisión: el frontend usa solo Tailwind CSS para estilos. Sin component libraries de tercero (Ant Design Vue, PrimeVue).
Justificación: el scope es un proyecto base. Alphinance usa Ant Design Vue + PrimeVue, pero agregarlos requiere configuración adicional (presets, themes) que está fuera del scope "auth básico". Si el dev quiere agregarlos, puede hacerlo en una segunda iteración. Las vistas de auth con Tailwind puro son suficientes para el scope declarado.

**DEC-10 — `useNotification` es un composable local, no un store completo**
Decisión: implementar `useNotification.ts` como composable con estado reactivo local (no Pinia store) que expone `notify(type, message)` y la lista de toasts. El componente de toast se implementa en el `AppLayout`.
Justificación: para auth básico, los toasts no necesitan estado global persistente. Alphinance tiene un store de notifications que maneja notificaciones del sistema (backend), no toasts de UI. El composable es suficiente para este scope.

---

## ESTRUCTURA DE DIRECTORIOS

### Backend (`mi-proyecto-back/`)

```
mi-proyecto-back/
├── app/
│   ├── Contracts/
│   │   └── Repositories/
│   │       └── UserRepositoryInterface.php
│   ├── Exceptions/
│   │   └── Handler.php                     (modificar)
│   ├── Helpers/
│   │   └── ResponseHelper.php              (crear — copia exacta de Alphinance)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php              (modificar — agregar ApiResponseTrait)
│   │   │   └── V1/
│   │   │       ├── AuthController.php
│   │   │       └── PasswordController.php
│   │   ├── Requests/
│   │   │   ├── LoginRequest.php
│   │   │   ├── RegisterRequest.php
│   │   │   ├── ForgotPasswordVerifyEmailRequest.php
│   │   │   ├── ForgotPasswordVerifyCodeRequest.php
│   │   │   ├── ForgotPasswordResendCodeRequest.php
│   │   │   └── ForgotPasswordResetPasswordRequest.php
│   │   └── Resources/
│   │       └── V1/
│   │           └── UserResource.php
│   ├── Mail/
│   │   ├── VerifyAccountEmail.php
│   │   └── ForgotPasswordEmail.php
│   ├── Models/
│   │   ├── User.php                        (modificar — agregar HasGuid, HasApiTokens, campos extra)
│   │   └── PasswordReset.php
│   ├── Providers/
│   │   └── AppServiceProvider.php          (modificar — bind repositorio)
│   ├── Repositories/
│   │   ├── BaseRepositoryEloquent.php      (crear — copia de Alphinance)
│   │   └── UserRepositoryEloquent.php
│   ├── Services/
│   │   ├── AuthService.php
│   │   └── ForgotPasswordService.php
│   └── Traits/
│       ├── ApiResponseTrait.php            (crear — copia de Alphinance)
│       └── HasGuid.php                     (crear — copia de Alphinance)
├── database/
│   └── migrations/
│       ├── 0001_01_01_000000_create_users_table.php           (modificar)
│       ├── 0001_01_01_000001_create_password_reset_tokens_table.php (reemplazar)
│       └── YYYY_MM_DD_create_password_resets_table.php        (crear)
├── routes/
│   ├── api.php                             (modificar — include pattern)
│   └── api/
│       └── auth.php
├── config/
│   ├── auth.php                            (modificar)
│   └── sanctum.php                         (modificar)
├── resources/
│   └── views/
│       └── emails/
│           ├── verify-account.blade.php
│           └── forgot-password.blade.php
└── .env.example
```

### Frontend (`mi-proyecto-front/`)

```
mi-proyecto-front/
└── src/
    ├── api/
    │   ├── http.ts
    │   └── auth.ts
    ├── assets/
    │   └── css/
    │       └── main.css
    ├── components/
    │   └── ui/
    │       ├── AppToast.vue
    │       └── AppButton.vue
    ├── composables/
    │   ├── useAuth.ts
    │   └── useNotification.ts
    ├── config/
    │   └── constants.ts
    ├── i18n/
    │   ├── index.ts
    │   └── locales/
    │       └── es/
    │           ├── global.ts
    │           └── auth.ts
    ├── layout/
    │   ├── AuthLayout.vue
    │   └── DashboardLayout.vue
    ├── router/
    │   ├── index.ts
    │   └── guards.ts
    ├── services/
    │   └── auth.service.ts
    ├── store/
    │   ├── auth.ts
    │   └── notification.ts
    ├── types/
    │   └── api/
    │       └── auth.types.ts
    ├── validators/
    │   └── auth.validators.ts
    ├── views/
    │   ├── auth/
    │   │   ├── LoginView.vue
    │   │   ├── RegisterView.vue
    │   │   ├── VerifyAccountView.vue
    │   │   ├── ForgotPasswordView.vue
    │   │   └── ResetPasswordView.vue
    │   └── dashboard/
    │       └── DashboardView.vue
    ├── App.vue
    └── main.ts
```

---

## Cambios en BACKEND

### Archivos a crear

#### `mi-proyecto-back/app/Traits/HasGuid.php`
**Propósito:** Trait que auto-genera UUID en `creating` y usa `guid` como route key. Copia exacta de Alphinance.
```php
namespace App\Traits;
use Illuminate\Support\Str;

trait HasGuid
{
    public function getRouteKeyName(): string { return 'guid'; }

    protected static function bootHasGuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->guid)) {
                $model->guid = Str::uuid()->toString();
            }
        });
    }
}
```

#### `mi-proyecto-back/app/Traits/ApiResponseTrait.php`
**Propósito:** Trait que expone `makeSuccess()`, `makeError()`, `makeNotFound()`, `makeFromException()` en todos los controllers. Copia exacta de Alphinance.
```php
namespace App\Traits;
use Illuminate\Http\JsonResponse;
use App\Helpers\ResponseHelper;

trait ApiResponseTrait
{
    public function makeSuccess($data, $message = null, $code = 200): JsonResponse
    { return ResponseHelper::successResponse($data, $message, $code); }

    public function makeError($errors = null, $message = null, $code = 400, ?string $errorCode = null): JsonResponse
    { return ResponseHelper::errorResponse($errors, $message, $code, $errorCode); }

    public function makeNotFound($message = null, $code = 404): JsonResponse
    { return ResponseHelper::notFoundResponse($message, $code); }

    public function makeFromException($exception): JsonResponse
    { return ResponseHelper::makeFromException($exception); }
}
```

#### `mi-proyecto-back/app/Helpers/ResponseHelper.php`
**Propósito:** Helper estático que genera respuestas JSON estandarizadas con shape `{success, data, message}`. Copia exacta de Alphinance. Mapea excepciones a HTTP codes.
**Firma principal:**
```php
class ResponseHelper
{
    public static function successResponse($data, $message = null, $code = 200): JsonResponse
    // Retorna: { "success": true, "data": $data, "message": $message }

    public static function errorResponse($errors = null, $message = null, int $code = 400, ?string $errorCode = null): JsonResponse
    // Retorna: { "success": false, "errors": $errors, "message": $message, "error_code"?: $errorCode }

    public static function notFoundResponse($message = null, int $code = 404): JsonResponse
    // Retorna: { "success": false, "message": $message }

    public static function makeFromException(Throwable $e): JsonResponse
    // Mapea: QueryException → 409/422/500, ValidationException → 422, HttpException → status del exception
}
```
Nota: en el nuevo proyecto la BD es MySQL (no PostgreSQL). El `mapPostgresError` debe renombrarse a `mapDbError` y los SQL states son iguales para MySQL (`23000` duplicado, `23000` FK violation). Ajustar match.

#### `mi-proyecto-back/app/Repositories/BaseRepositoryEloquent.php`
**Propósito:** Base para todos los repositorios. Extiende `Prettus\Repository\Eloquent\BaseRepository` y agrega helpers `findByGuid`, `updateByGuid`, `newQuery`. Copia exacta de Alphinance.
**Dependencias:** `prettus/l5-repository` instalado via Composer.

#### `mi-proyecto-back/app/Contracts/Repositories/UserRepositoryInterface.php`
**Propósito:** Contrato para el repositorio de User. Permite inyección de dependencias por interfaz.
```php
namespace App\Contracts\Repositories;

interface UserRepositoryInterface
{
    public function findByEmail(string $email);
    public function findByGuid(string $guid);
    public function create(array $data);
    public function updatePassword($user, string $password);
    public function storePasswordHistory($user, string $hashedPassword, int $limit = 5): void;
}
```

#### `mi-proyecto-back/app/Repositories/UserRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio de User. Extiende `BaseRepositoryEloquent` e implementa `UserRepositoryInterface`.
```php
namespace App\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserRepositoryEloquent extends BaseRepositoryEloquent implements UserRepositoryInterface
{
    public function model(): string { return User::class; }

    public function findByEmail(string $email) { return $this->model->where('email', $email)->first(); }

    public function findByGuid(string $guid) { return $this->model->where('guid', $guid)->first(); }

    public function updatePassword($user, string $password): User
    {
        $user->password = Hash::make($password);
        $user->failed_login_attempts = 0;
        $user->locked_at = null;
        $user->password_changed_at = now();
        $user->save();
        $this->storePasswordHistory($user, $user->password);
        return $user;
    }

    public function storePasswordHistory($user, string $hashedPassword, int $limit = 5): void
    {
        // Registra en tabla user_password_histories (crear migration separada en iteración futura)
        // En esta iteración: no-op. Placeholder para extensión futura.
    }
}
```

#### `mi-proyecto-back/app/Models/PasswordReset.php`
**Propósito:** Modelo Eloquent para la tabla `password_resets`. Sin timestamps propios (usa `updated_at` para detectar expiración).
```php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    protected $table = 'password_resets';
    protected $fillable = ['user_id', 'token', 'code', 'used'];

    protected function casts(): array
    {
        return ['used' => 'boolean'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
```

#### `mi-proyecto-back/app/Services/AuthService.php`
**Propósito:** Lógica de negocio de autenticación. Métodos: `signup`, `login`, `logout`, `verifyCode`, `resendVerificationCode`.
```php
namespace App\Services;

use App\Repositories\UserRepositoryEloquent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Mail\VerifyAccountEmail;

class AuthService
{
    public function __construct(
        private UserRepositoryEloquent $userRepository,
    ) {}

    /**
     * Registra un nuevo usuario. Envía email de verificación con código 6 dígitos.
     * @return array{guid: string, email: string}
     */
    public function signup(array $data): array
    {
        // 1. Generar código de verificación (6 dígitos) y token de verificación
        // 2. DB::beginTransaction()
        // 3. $this->userRepository->create([...]) — password hasheado
        // 4. $this->sendVerificationEmail($user, $code, $token, $expirationMinutes)
        // 5. DB::commit()
        // 6. return ['guid' => $user->guid, 'email' => $user->email]
        // En catch: DB::rollBack(); throw $e;
    }

    /**
     * Autentica un usuario ya validado por LoginRequest.
     * @param  User  $user  — el modelo ya cargado por el FormRequest
     * @return array{access_token: string, user: array, must_verify_account: bool}
     */
    public function login(User $user): array
    {
        // 1. Si !$user->email_verified_at: return ['must_verify_account' => true, 'user' => $this->formatUser($user)]
        // 2. Revocar tokens anteriores: $user->tokens()->delete()
        // 3. Emitir nuevo token: $token = $user->createToken('api-access', ['*'])
        // 4. Actualizar last_login_at, resetear failed_login_attempts
        // 5. return ['access_token' => $token->plainTextToken, 'user' => $this->formatUser($user), 'must_verify_account' => false]
    }

    /**
     * Revoca el token actual del usuario.
     */
    public function logout(Request $request): void
    {
        $request->user()?->currentAccessToken()?->delete();
    }

    /**
     * Verifica el código de 6 dígitos enviado al email en el registro.
     */
    public function verifyCode(array $data): void
    {
        // 1. Buscar user por guid
        // 2. Validar que !$user->email_verified_at
        // 3. Validar código y expiración
        // 4. Marcar email_verified_at = now(), limpiar campos de verificación
    }

    /**
     * Reenvía el código de verificación al email.
     */
    public function resendVerificationCode(array $data): array
    {
        // 1. Buscar user por guid
        // 2. Validar que !$user->email_verified_at
        // 3. Cooldown de 2 minutos (last_verification_email_sent_at)
        // 4. Generar nuevo código y token, actualizar campos en el user
        // 5. Enviar email
        // 6. return ['guid' => $user->guid, 'email' => $user->email]
    }

    /** @return array{id,guid,first_name,last_name,email} */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'guid' => $user->guid,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }

    private function generateVerificationCode(): string
    { return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); }

    private function sendVerificationEmail(User $user, string $code, string $token, int $minutes): void
    {
        try {
            Mail::to($user->email)->send(new VerifyAccountEmail($user, $code, $token, $minutes));
        } catch (\Exception $e) {
            logger($e->getMessage());
        }
    }
}
```

#### `mi-proyecto-back/app/Services/ForgotPasswordService.php`
**Propósito:** Lógica de recuperación de contraseña en 3 pasos: verificar email → ingresar código → nueva contraseña. Copia del patrón de Alphinance.
```php
namespace App\Services;

use App\Repositories\UserRepositoryEloquent;
use App\Models\PasswordReset;
use App\Mail\ForgotPasswordEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ForgotPasswordService
{
    public function __construct(
        private UserRepositoryEloquent $userRepository,
        private PasswordReset $passwordReset,
    ) {}

    public function verifyEmail(array $data): array
    // 1. Buscar user por email. Si no existe: throw \Exception('Email no registrado.')
    // 2. Llamar a createAndSendCode($user)
    // 3. return ['guid' => $user->guid, 'email' => $user->email, 'token' => $token]

    public function verifyCode(array $data): void
    // 1. Buscar PasswordReset por token + code. Si no existe o $used: throw \Exception(...)
    // 2. Verificar expiración (updated_at + N minutos). Si expiró: throw
    // 3. $passwordReset->update(['used' => true])

    public function resendCode(array $data): array
    // 1. Buscar user por guid
    // 2. Llamar a createAndSendCode($user)
    // 3. return ['guid', 'email', 'token']

    public function resetPassword(array $data): void
    // 1. Buscar user por guid
    // 2. $this->userRepository->updatePassword($user, $data['password'])

    private function createAndSendCode(User $user): array
    // 1. Generar código 6 dígitos y token aleatorio 64 chars
    // 2. PasswordReset::updateOrCreate(['user_id' => $user->id], ['token', 'code', 'used' => false])
    // 3. Enviar ForgotPasswordEmail
    // 4. return ['token', 'code']
}
```

#### `mi-proyecto-back/app/Http/Controllers/V1/AuthController.php`
**Propósito:** Controller thin de auth. Delega todo a `AuthService`. Sigue el patrón de Alphinance: try/catch, `makeSuccess()` / `makeFromException()`.
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    public function login(LoginRequest $request): JsonResponse
    // $request->user es el User cargado por LoginRequest::prepareForValidation()
    public function logout(Request $request): JsonResponse
    public function profile(Request $request): JsonResponse
    public function verifyCode(VerifyCodeRequest $request): JsonResponse
    public function resendCode(ResendVerificationCodeRequest $request): JsonResponse
}
```

#### `mi-proyecto-back/app/Http/Controllers/V1/PasswordController.php`
**Propósito:** Controller thin de forgot/reset password. Delega a `ForgotPasswordService`.
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordVerifyEmailRequest;
use App\Http\Requests\ForgotPasswordVerifyCodeRequest;
use App\Http\Requests\ForgotPasswordResendCodeRequest;
use App\Http\Requests\ForgotPasswordResetPasswordRequest;
use App\Services\ForgotPasswordService;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function __construct(private ForgotPasswordService $forgotPasswordService) {}

    public function verifyEmail(ForgotPasswordVerifyEmailRequest $request): JsonResponse
    public function verifyCode(ForgotPasswordVerifyCodeRequest $request): JsonResponse
    public function resendCode(ForgotPasswordResendCodeRequest $request): JsonResponse
    public function resetPassword(ForgotPasswordResetPasswordRequest $request): JsonResponse
}
```

#### `mi-proyecto-back/app/Http/Requests/LoginRequest.php`
**Propósito:** Valida credenciales, carga el modelo `User` en `$this->user` para evitar segunda query en el controller. Verifica contraseña, intentos fallidos y bloqueo.
```php
// Campos:
//   email — required|email|exists:users,email
//   password — required
//   remember — boolean (opcional)
//
// prepareForValidation(): carga $this->user = User::where('email', $this->email)->first()
// withValidator(): verifica Hash::check(password), failed_login_attempts, locked_at
// messages(): mensajes en español
```
**Nota:** copiar la lógica de `LoginRequest.php` de Alphinance. La tabla de usuarios del nuevo proyecto tiene los mismos campos (`failed_login_attempts`, `locked_at`) porque la migration los incluye.

#### `mi-proyecto-back/app/Http/Requests/RegisterRequest.php`
**Propósito:** Valida datos del formulario de registro.
```php
// Campos:
//   first_name — required|string|max:50|regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/
//   last_name  — required|string|max:50|regex:/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/
//   email      — required|email|unique:users,email
//   password   — required|confirmed|between:8,12|regex mayúscula|regex número|regex símbolo !@#$%&
// messages(): mensajes en español
```

#### `mi-proyecto-back/app/Http/Requests/ForgotPasswordVerifyEmailRequest.php`
```php
// Campos: email — required|email|exists:users,email
```

#### `mi-proyecto-back/app/Http/Requests/ForgotPasswordVerifyCodeRequest.php`
```php
// Campos:
//   token — required|string|size:64
//   code  — required|string|size:6
```

#### `mi-proyecto-back/app/Http/Requests/ForgotPasswordResendCodeRequest.php`
```php
// Campos: guid — required|string
```

#### `mi-proyecto-back/app/Http/Requests/ForgotPasswordResetPasswordRequest.php`
```php
// Campos:
//   guid                 — required|string
//   password             — required|confirmed|between:8,12|regex mayúscula|regex número|regex símbolo
//   password_confirmation — required
```

#### `mi-proyecto-back/app/Http/Requests/VerifyCodeRequest.php`
```php
// Campos: guid — required|string, code — required|string|size:6
```

#### `mi-proyecto-back/app/Http/Requests/ResendVerificationCodeRequest.php`
```php
// Campos: guid — required|string
```

#### `mi-proyecto-back/app/Http/Resources/V1/UserResource.php`
**Propósito:** API Resource que shapea el modelo User para las respuestas.
```php
namespace App\Http\Resources\V1;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'guid'       => $this->guid,
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'email'      => $this->email,
            'last_login_at' => $this->last_login_at?->toISOString(),
        ];
    }
}
```

#### `mi-proyecto-back/app/Mail/VerifyAccountEmail.php`
**Propósito:** Mailable para el email de verificación de cuenta post-registro. Extiende `Mailable`.
```php
// Constructor: (User $user, string $code, string $token, int $expirationMinutes)
// build(): retorna vista 'emails.verify-account' con $code, $token, $expirationMinutes, $user->first_name
```

#### `mi-proyecto-back/app/Mail/ForgotPasswordEmail.php`
**Propósito:** Mailable para el email de recuperación de contraseña.
```php
// Constructor: (User $user, string $code, string $token, int $expirationMinutes)
// build(): retorna vista 'emails.forgot-password' con $code, $token
```

---

### Archivos a modificar

#### `mi-proyecto-back/app/Http/Controllers/Controller.php`
**Cambio:** agregar `use ApiResponseTrait` para que todos los controllers hereden los métodos de respuesta.
```php
// Antes:
abstract class Controller { }

// Después:
use App\Traits\ApiResponseTrait;
abstract class Controller {
    use ApiResponseTrait;
}
```

#### `mi-proyecto-back/app/Models/User.php`
**Cambio:** agregar traits `HasGuid`, `HasApiTokens` (Sanctum), campos extra en `$fillable` y `$casts`.
```php
// Agregar traits: HasGuid, HasApiTokens, Notifiable
// Agregar a $fillable:
//   'guid', 'first_name', 'last_name', 'verification_code',
//   'verification_code_expires_at', 'verification_link_token',
//   'verification_link_expires_at', 'last_verification_email_sent_at',
//   'password_changed_at', 'failed_login_attempts', 'locked_at', 'last_login_at'

// Agregar a casts():
//   'email_verified_at' => 'datetime',
//   'password' => 'hashed',
//   'locked_at' => 'datetime',
//   'failed_login_attempts' => 'integer',
//   'verification_code_expires_at' => 'datetime',
//   'last_verification_email_sent_at' => 'datetime',
//   'password_changed_at' => 'datetime',
//   'last_login_at' => 'datetime',
```

#### `mi-proyecto-back/app/Providers/AppServiceProvider.php`
**Cambio:** registrar el binding de la interfaz `UserRepositoryInterface` a `UserRepositoryEloquent`.
```php
// En register():
$this->app->bind(
    \App\Contracts\Repositories\UserRepositoryInterface::class,
    \App\Repositories\UserRepositoryEloquent::class,
);
```

#### `mi-proyecto-back/app/Exceptions/Handler.php`
**Cambio:** sobrescribir `render()` para responder JSON en todas las excepciones cuando el request espera JSON. Incluir manejo de `AuthenticationException` → 401, `AuthorizationException` → 403, `ValidationException` → 422, `ModelNotFoundException` → 404.
```php
public function render($request, Throwable $e)
{
    if ($request->expectsJson()) {
        return \App\Helpers\ResponseHelper::makeFromException($e);
    }
    return parent::render($request, $e);
}
```

---

### Migrations

#### Migration principal `users` (modificar la existente `0001_01_01_000000_create_users_table.php`)
Reemplazar la migration de users estándar para agregar los campos extra:

```
Tabla: users
Columnas:
  - id — bigint unsigned, PK, auto-increment
  - guid — char(36), unique, not null
  - first_name — varchar(50), not null
  - last_name — varchar(50), not null
  - name — varchar(191), not null (generado: first_name + ' ' + last_name)
  - email — varchar(191), unique, not null
  - email_verified_at — timestamp, nullable
  - password — varchar(191), not null
  - failed_login_attempts — integer, default 0
  - locked_at — timestamp, nullable
  - last_login_at — timestamp, nullable
  - password_changed_at — timestamp, nullable
  - verification_code — varchar(6), nullable
  - verification_code_expires_at — timestamp, nullable
  - verification_link_token — varchar(64), nullable
  - verification_link_expires_at — timestamp, nullable
  - last_verification_email_sent_at — timestamp, nullable
  - remember_token — varchar(100), nullable
  - timestamps (created_at, updated_at)

Índices: unique(email), unique(guid)
```
Reversible: `dropIfExists('users')`.

#### Migration nueva: `password_resets` (crear, reemplazar la de Laravel)
Eliminar la migration `0001_01_01_000001_create_password_reset_tokens_table.php` (no usar la tabla de Laravel). Crear nueva:

```
Tabla: password_resets
Columnas:
  - id — bigint unsigned, PK
  - user_id — bigint unsigned, not null, FK → users.id, onDelete cascade
  - token — varchar(64), not null
  - code — varchar(6), not null
  - used — boolean, default false
  - timestamps (created_at, updated_at)

Índices: unique(user_id), index(token)
```
Reversible: `dropIfExists('password_resets')`.

#### Migration: `personal_access_tokens` (la crea Sanctum automáticamente)
No crear manualmente. Se genera con `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"` y luego `php artisan migrate`.

---

### Rutas API

Archivo `routes/api.php` (patrón de Alphinance — include loop):
```php
foreach (glob(__DIR__ . '/api/*.php') as $routeFile) {
    require $routeFile;
}
```

Archivo `routes/api/auth.php`:

| Método | Path | Controller@action | Middleware | Auth requerida |
|--------|------|-------------------|------------|----------------|
| POST | /api/v1/auth/register | AuthController@register | throttle:10,1 | No |
| POST | /api/v1/auth/login | AuthController@login | throttle:10,1 | No |
| POST | /api/v1/auth/logout | AuthController@logout | auth:sanctum | Si |
| GET  | /api/v1/auth/profile | AuthController@profile | auth:sanctum | Si |
| POST | /api/v1/auth/verify-account/verify-code | AuthController@verifyCode | throttle:10,1 | No |
| POST | /api/v1/auth/verify-account/resend-code | AuthController@resendCode | throttle:5,1 | No |
| POST | /api/v1/auth/forgot-password/verify-email | PasswordController@verifyEmail | throttle:5,1 | No |
| POST | /api/v1/auth/forgot-password/verify-code | PasswordController@verifyCode | throttle:10,1 | No |
| POST | /api/v1/auth/forgot-password/resend-code | PasswordController@resendCode | throttle:5,1 | No |
| POST | /api/v1/auth/forgot-password/reset-password | PasswordController@resetPassword | throttle:5,1 | No |

Estructura del archivo:
```php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\PasswordController;

Route::prefix('v1/auth')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('profile', [AuthController::class, 'profile']);
    });

    Route::prefix('verify-account')->middleware('throttle:10,1')->group(function () {
        Route::post('verify-code', [AuthController::class, 'verifyCode']);
        Route::post('resend-code', [AuthController::class, 'resendCode']);
    });

    Route::prefix('forgot-password')->middleware('throttle:5,1')->group(function () {
        Route::post('verify-email', [PasswordController::class, 'verifyEmail']);
        Route::post('verify-code', [PasswordController::class, 'verifyCode']);
        Route::post('resend-code', [PasswordController::class, 'resendCode']);
        Route::post('reset-password', [PasswordController::class, 'resetPassword']);
    });
});
```

---

### Contrato de los endpoints

#### POST /api/v1/auth/register
Request:
```json
{
  "first_name": "string, required, max:50, solo letras",
  "last_name": "string, required, max:50, solo letras",
  "email": "string, required, email, unique:users",
  "password": "string, required, 8-12 chars, confirmed, mayúscula + número + símbolo !@#$%&",
  "password_confirmation": "string, required"
}
```
Response 200:
```json
{
  "success": true,
  "data": { "guid": "uuid", "email": "user@example.com" },
  "message": "Registro exitoso. Revisá tu email para verificar la cuenta."
}
```
Errores: 422 (validación), 500 (error de DB).

#### POST /api/v1/auth/login
Request:
```json
{
  "email": "string, required, email",
  "password": "string, required",
  "remember": "boolean, opcional"
}
```
Response 200 (autenticado):
```json
{
  "success": true,
  "data": {
    "access_token": "1|abcdef...",
    "user": { "id": 1, "guid": "uuid", "first_name": "Juan", "last_name": "Pérez", "email": "juan@example.com", "last_login_at": "2026-05-12T10:00:00Z" },
    "must_verify_account": false
  }
}
```
Response 200 (email no verificado):
```json
{
  "success": true,
  "data": { "must_verify_account": true, "user": { "guid": "uuid", "email": "..." } }
}
```
Errores: 422 (password incorrecta, cuenta bloqueada), 500.

#### POST /api/v1/auth/logout
Headers: `Authorization: Bearer {token}`
Request: body vacío (o `{}`)
Response 200:
```json
{ "success": true, "data": null, "message": "Sesión cerrada correctamente." }
```
Errores: 401 (token inválido).

#### GET /api/v1/auth/profile
Headers: `Authorization: Bearer {token}`
Response 200:
```json
{
  "success": true,
  "data": { "id": 1, "guid": "uuid", "first_name": "...", "last_name": "...", "email": "..." }
}
```

#### POST /api/v1/auth/verify-account/verify-code
Request: `{ "guid": "uuid", "code": "123456" }`
Response 200: `{ "success": true, "data": {}, "message": "Email verificado correctamente." }`
Errores: 422 (código inválido, expirado).

#### POST /api/v1/auth/verify-account/resend-code
Request: `{ "guid": "uuid" }`
Response 200: `{ "success": true, "data": { "guid": "uuid", "email": "..." } }`
Errores: 422 (cooldown activo).

#### POST /api/v1/auth/forgot-password/verify-email
Request: `{ "email": "user@example.com" }`
Response 200: `{ "success": true, "data": { "guid": "uuid", "email": "...", "token": "64chars" } }`

#### POST /api/v1/auth/forgot-password/verify-code
Request: `{ "token": "64chars", "code": "123456" }`
Response 200: `{ "success": true, "data": {} }`
Errores: 422 (código inválido, token expirado).

#### POST /api/v1/auth/forgot-password/reset-password
Request:
```json
{
  "guid": "uuid",
  "password": "Nueva1@",
  "password_confirmation": "Nueva1@"
}
```
Response 200: `{ "success": true, "data": {}, "message": "Contraseña actualizada correctamente." }`

---

### Variables de entorno (`.env.example`)

```ini
APP_NAME="Mi Proyecto"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mi_proyecto
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000
VITE_API_BASE_URL=http://localhost:8000/api

# Tiempo de expiración del token de acceso en minutos (null = sin expiración)
SANCTUM_ACCESS_TOKEN_EXPIRATION=

# Minutos antes de que expire el código de verificación de email
AUTH_VERIFICATION_CODE_EXPIRATION=10

# Máximo de intentos fallidos de login antes de bloquear la cuenta
AUTH_MAX_FAILED_LOGIN_ATTEMPTS=3

# Meses antes de que expire la contraseña
AUTH_PASSWORD_EXPIRE_MONTHS=6

FRONTEND_URL=http://localhost:5173
```

### Configuración `config/sanctum.php`
Agregar al array de Sanctum:
```php
'access_token_expiration' => env('SANCTUM_ACCESS_TOKEN_EXPIRATION'),
```

### Configuración `config/auth.php`
Agregar valores custom:
```php
'verification_code_expiration' => env('AUTH_VERIFICATION_CODE_EXPIRATION', 10),
'max_failed_login_attempts'    => env('AUTH_MAX_FAILED_LOGIN_ATTEMPTS', 3),
'passwords' => [
    'expire_months' => env('AUTH_PASSWORD_EXPIRE_MONTHS', 6),
],
```

### Configuración CORS
En `config/cors.php` (o `bootstrap/app.php` en Laravel 12):
```php
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — `tests/Feature/Auth/`:**

`RegisterTest.php`:
1. Happy path: registro válido → 200, recibe guid y email.
2. Email ya registrado → 422.
3. Password sin mayúscula → 422.
4. Password sin número → 422.
5. Password sin símbolo → 422.
6. Password sin confirmación → 422.
7. First name con números → 422.

`LoginTest.php`:
1. Happy path: login válido → 200, recibe access_token y user.
2. Email no verificado → 200, `must_verify_account: true`.
3. Password incorrecta → 422 con mensaje de intentos restantes.
4. Cuenta bloqueada → 422 con mensaje de bloqueo.
5. Email no registrado → 422 (`email.exists`).

`LogoutTest.php`:
1. Happy path: token válido → 200, token revocado.
2. Sin token → 401.

`VerifyAccountTest.php`:
1. Código válido → 200, `email_verified_at` seteado.
2. Código incorrecto → 422.
3. Código expirado → 422.
4. Email ya verificado → 422.

`ForgotPasswordTest.php`:
1. verify-email con email registrado → 200, recibe guid + token.
2. verify-email con email no registrado → 422.
3. verify-code correcto → 200.
4. verify-code incorrecto → 422.
5. reset-password válido → 200, contraseña actualizada.
6. reset-password con password inválida → 422.

---

## Cambios en FRONTEND

### Archivos a crear

#### `mi-proyecto-front/src/config/constants.ts`
```typescript
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL as string;
export const APP_NAME = import.meta.env.VITE_APP_NAME ?? 'Mi Proyecto';
export const AUTH_MODE = 'token' as const;  // Solo token en esta iteración

export const ROUTES = {
    login: '/login',
    register: '/register',
    dashboard: '/dashboard',
    verifyAccount: '/verify-account',
    forgotPassword: '/forgot-password',
};
```

#### `mi-proyecto-front/src/api/http.ts`
**Propósito:** Instancia de Axios con interceptors. Replica el patrón de Alphinance en modo token únicamente (sin lógica de cookie mode para simplificar).
```typescript
import axios, { AxiosHeaders } from 'axios';
import type { AxiosInstance, AxiosResponse, AxiosError, InternalAxiosRequestConfig } from 'axios';
import { API_BASE_URL } from '@/config/constants';

export const http = axios.create({
    baseURL: API_BASE_URL,
    timeout: 30000,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
}) as AxiosInstance;

// REQUEST INTERCEPTOR
// - Inyectar Authorization: Bearer {token} si hay token en el store
// - Excluir rutas /auth/ (login, register, forgot-password)

// RESPONSE INTERCEPTOR — normalizeSuccessResponse:
// - Si payload.success === true → resp.data = payload.data (desenvuelve la capa success/data/message)
// - Si payload.success === false → Promise.reject({ success: false, message, errors })

// ERROR INTERCEPTOR:
// 401 → si hay refreshToken y no estamos en /auth/ → intentar refresh
//        si refresh falla → logout + router.push('/login')
// 422 → Promise.reject({ success: false, fields: payload.errors, message })
// 500 → Promise.reject({ success: false, message })
// otros → Promise.reject({ success: false, message })
```
**Dependencias:** importa `useAuthStore` de Pinia, `router` de Vue Router.

#### `mi-proyecto-front/src/api/auth.ts`
**Propósito:** Definiciones de tipos e interfaces + funciones de llamada a la API de auth.
```typescript
import { http } from './http';

// ── Tipos ──────────────────────────────────────────────────────────────────

export interface LoginPayload { email: string; password: string; remember?: boolean; }
export interface LoginResponse {
    access_token: string;
    user: UserApiResponse;
    must_verify_account: boolean;
}
export interface UserApiResponse {
    id: number;
    guid: string;
    first_name: string;
    last_name: string;
    email: string;
    last_login_at: string | null;
}
export interface RegisterPayload {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
}
export interface RegisterResponse { guid: string; email: string; }

export interface VerifyCodePayload { guid: string; code: string; }
export interface ResendCodePayload { guid: string; }

export interface ForgotPasswordVerifyEmailPayload { email: string; }
export interface ForgotPasswordVerifyEmailResponse { guid: string; email: string; token: string; }

export interface ForgotPasswordVerifyCodePayload { token: string; code: string; }
export interface ForgotPasswordResendCodePayload { guid: string; }
export interface ForgotPasswordResetPasswordPayload {
    guid: string;
    password: string;
    password_confirmation: string;
}

// ── Funciones ──────────────────────────────────────────────────────────────

export async function loginApi(payload: LoginPayload): Promise<LoginResponse>
// POST /v1/auth/login

export async function registerApi(payload: RegisterPayload): Promise<RegisterResponse>
// POST /v1/auth/register

export async function logoutApi(): Promise<void>
// POST /v1/auth/logout

export async function profileApi(): Promise<UserApiResponse>
// GET /v1/auth/profile

export async function verifyCodeApi(payload: VerifyCodePayload): Promise<void>
// POST /v1/auth/verify-account/verify-code

export async function resendVerificationCodeApi(payload: ResendCodePayload): Promise<{ guid: string; email: string; }>
// POST /v1/auth/verify-account/resend-code

export async function forgotPasswordVerifyEmailApi(payload: ForgotPasswordVerifyEmailPayload): Promise<ForgotPasswordVerifyEmailResponse>
// POST /v1/auth/forgot-password/verify-email

export async function forgotPasswordVerifyCodeApi(payload: ForgotPasswordVerifyCodePayload): Promise<void>
// POST /v1/auth/forgot-password/verify-code

export async function forgotPasswordResendCodeApi(payload: ForgotPasswordResendCodePayload): Promise<{ guid: string; email: string; token: string; }>
// POST /v1/auth/forgot-password/resend-code

export async function forgotPasswordResetPasswordApi(payload: ForgotPasswordResetPasswordPayload): Promise<void>
// POST /v1/auth/forgot-password/reset-password
```

#### `mi-proyecto-front/src/types/api/auth.types.ts`
**Propósito:** Tipos de dominio del frontend para el estado de auth (distinto de los tipos de payload de API).
```typescript
export interface User {
    id: number;
    guid: string;
    first_name: string;
    last_name: string;
    email: string;
    last_login_at: string | null;
}

export interface AuthState {
    token: string | null;
    user: User | null;
    isAuthenticated: boolean;
    mustVerifyAccount: boolean;
}
```

#### `mi-proyecto-front/src/store/auth.ts`
**Propósito:** Store Pinia que gestiona el estado de autenticación. Persiste en localStorage via `pinia-plugin-persistedstate`.
```typescript
import { defineStore } from 'pinia';
import type { User } from '@/types/api/auth.types';
import { loginApi, registerApi, logoutApi, profileApi } from '@/api/auth';

interface AuthState {
    token: string | null;
    user: User | null;
    isLoggingIn: boolean;
    isLoggingOut: boolean;
    mustVerifyAccount: boolean;
}

export const useAuthStore = defineStore('auth', {
    state: (): AuthState => ({
        token: null,
        user: null,
        isLoggingIn: false,
        isLoggingOut: false,
        mustVerifyAccount: false,
    }),

    getters: {
        isAuthenticated: (s): boolean => Boolean(s.user && s.token),
    },

    actions: {
        // login(email, password, remember): llama loginApi, guarda token y user.
        //   Si must_verify_account: guarda guid en state para redirigir a verificación.
        async login(email: string, password: string, remember = false): Promise<{ must_verify_account?: boolean; user?: User }>,

        // register(payload): llama registerApi, retorna guid para redirigir a verificación.
        async register(payload: RegisterPayload): Promise<{ guid: string; email: string }>,

        // logout(): llama logoutApi, limpia state y localStorage.
        async logout(): Promise<void>,

        // fetchUser(): GET /auth/profile y actualiza this.user.
        async fetchUser(): Promise<void>,
    },

    persist: true,  // pinia-plugin-persistedstate
});
```

#### `mi-proyecto-front/src/store/notification.ts`
**Propósito:** Store Pinia liviano para toasts de UI (errores, éxitos). Sin llamadas a API.
```typescript
import { defineStore } from 'pinia';

interface Toast {
    id: string;
    type: 'success' | 'error' | 'warning' | 'info';
    message: string;
}

export const useNotificationStore = defineStore('notification', {
    state: () => ({ toasts: [] as Toast[] }),

    actions: {
        push(type: Toast['type'], message: string): void
        // Agrega toast con id único (crypto.randomUUID()), auto-remove a los 4s.

        remove(id: string): void
        // Remueve toast por id.
    },
});
```

#### `mi-proyecto-front/src/composables/useAuth.ts`
```typescript
import { useAuthStore } from '@/store/auth';
import { storeToRefs } from 'pinia';

export function useAuth() {
    const store = useAuthStore();
    const { user, token, isAuthenticated, mustVerifyAccount } = storeToRefs(store);

    return {
        user,
        token,
        isAuthenticated,
        mustVerifyAccount,
        login: store.login,
        register: store.register,
        logout: store.logout,
        fetchUser: store.fetchUser,
    };
}
```

#### `mi-proyecto-front/src/composables/useNotification.ts`
```typescript
import { useNotificationStore } from '@/store/notification';

export function useNotification() {
    const store = useNotificationStore();

    return {
        toasts: store.toasts,
        success: (msg: string) => store.push('success', msg),
        error: (msg: string) => store.push('error', msg),
        warning: (msg: string) => store.push('warning', msg),
        info: (msg: string) => store.push('info', msg),
    };
}
```

#### `mi-proyecto-front/src/services/auth.service.ts`
**Propósito:** Capa de servicio que envuelve las llamadas a `api/auth.ts`. Centraliza manejo de errores de API y extracción de mensajes.
```typescript
import * as authApi from '@/api/auth';
import type { LoginPayload, RegisterPayload } from '@/api/auth';

export const AuthService = {
    async login(payload: LoginPayload) { return authApi.loginApi(payload); },
    async register(payload: RegisterPayload) { return authApi.registerApi(payload); },
    async logout() { return authApi.logoutApi(); },
    async fetchProfile() { return authApi.profileApi(); },
    async verifyCode(guid: string, code: string) { return authApi.verifyCodeApi({ guid, code }); },
    async resendCode(guid: string) { return authApi.resendVerificationCodeApi({ guid }); },
    async forgotPasswordVerifyEmail(email: string) { return authApi.forgotPasswordVerifyEmailApi({ email }); },
    async forgotPasswordVerifyCode(token: string, code: string) { return authApi.forgotPasswordVerifyCodeApi({ token, code }); },
    async forgotPasswordResendCode(guid: string) { return authApi.forgotPasswordResendCodeApi({ guid }); },
    async forgotPasswordResetPassword(guid: string, password: string, password_confirmation: string) {
        return authApi.forgotPasswordResetPasswordApi({ guid, password, password_confirmation });
    },
};
```

#### `mi-proyecto-front/src/validators/auth.validators.ts`
**Propósito:** Schemas Zod para validación de formularios de auth.
```typescript
import { z } from 'zod';

const passwordSchema = z.string()
    .min(8, 'Mínimo 8 caracteres')
    .max(12, 'Máximo 12 caracteres')
    .regex(/[A-Z]/, 'Debe contener al menos una mayúscula')
    .regex(/[0-9]/, 'Debe contener al menos un número')
    .regex(/[!@#$%&]/, 'Debe contener al menos un símbolo (!@#$%&)');

export const loginSchema = z.object({
    email: z.string().email('Email inválido'),
    password: z.string().min(1, 'La contraseña es requerida'),
    remember: z.boolean().optional(),
});

export const registerSchema = z.object({
    first_name: z.string().min(1).max(50).regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    last_name: z.string().min(1).max(50).regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    email: z.string().email('Email inválido'),
    password: passwordSchema,
    password_confirmation: z.string(),
}).refine(d => d.password === d.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
});

export const verifyCodeSchema = z.object({
    code: z.string().length(6, 'El código debe tener 6 dígitos'),
});

export const forgotPasswordEmailSchema = z.object({
    email: z.string().email('Email inválido'),
});

export const resetPasswordSchema = z.object({
    password: passwordSchema,
    password_confirmation: z.string(),
}).refine(d => d.password === d.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
});

export type LoginForm = z.infer<typeof loginSchema>;
export type RegisterForm = z.infer<typeof registerSchema>;
export type VerifyCodeForm = z.infer<typeof verifyCodeSchema>;
export type ForgotPasswordEmailForm = z.infer<typeof forgotPasswordEmailSchema>;
export type ResetPasswordForm = z.infer<typeof resetPasswordSchema>;
```

#### `mi-proyecto-front/src/router/index.ts`
```typescript
import { createRouter, createWebHistory } from 'vue-router';
import AuthLayout from '@/layout/AuthLayout.vue';
import DashboardLayout from '@/layout/DashboardLayout.vue';
import { authGuard } from './guards';

const routes = [
    {
        path: '/auth',
        component: AuthLayout,
        children: [
            { path: '/login', name: 'login', meta: { public: true }, component: () => import('@/views/auth/LoginView.vue') },
            { path: '/register', name: 'register', meta: { public: true }, component: () => import('@/views/auth/RegisterView.vue') },
            { path: '/verify-account/:guid', name: 'verifyAccount', meta: { public: true }, props: true, component: () => import('@/views/auth/VerifyAccountView.vue') },
            { path: '/forgot-password', name: 'forgotPassword', meta: { public: true }, component: () => import('@/views/auth/ForgotPasswordView.vue') },
            { path: '/reset-password/:guid', name: 'resetPassword', meta: { public: true }, props: true, component: () => import('@/views/auth/ResetPasswordView.vue') },
        ]
    },
    {
        path: '/',
        component: DashboardLayout,
        children: [
            { path: '/dashboard', name: 'dashboard', component: () => import('@/views/dashboard/DashboardView.vue') },
        ]
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

export const router = createRouter({ history: createWebHistory(), routes });
router.beforeEach(authGuard);
```

#### `mi-proyecto-front/src/router/guards.ts`
```typescript
import type { NavigationGuard } from 'vue-router';
import { useAuthStore } from '@/store/auth';
import { ROUTES } from '@/config/constants';

export const authGuard: NavigationGuard = (to) => {
    const auth = useAuthStore();

    // Si la ruta es pública y el usuario está autenticado → redirigir al dashboard
    if (to.meta?.public && auth.isAuthenticated) {
        return { path: ROUTES.dashboard, replace: true };
    }

    // Si la ruta NO es pública y el usuario NO está autenticado → redirigir al login
    if (!to.meta?.public && !auth.isAuthenticated) {
        return { path: ROUTES.login, replace: true };
    }

    return true;
};
```

#### `mi-proyecto-front/src/layout/AuthLayout.vue`
**Propósito:** Layout para todas las vistas de auth. Centra el contenido, fondo con gradiente oscuro. Incluye `<RouterView />`.
```html
<template>
  <div class="min-h-screen bg-gradient-to-br from-slate-900 to-slate-700 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <!-- Logo / nombre de la app -->
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-white">{{ appName }}</h1>
      </div>
      <!-- Card del formulario -->
      <div class="bg-white rounded-2xl shadow-xl p-8">
        <RouterView />
      </div>
    </div>
    <!-- Toast container -->
    <AppToast />
  </div>
</template>
```

#### `mi-proyecto-front/src/layout/DashboardLayout.vue`
**Propósito:** Layout para vistas protegidas. Sidebar izquierdo oscuro, topbar, área de contenido. Solo contiene `<RouterView />` en el main. No tiene lógica de negocio.
```html
<template>
  <div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <aside class="w-64 bg-slate-900 text-white flex flex-col">
      <div class="p-4 border-b border-slate-700">
        <span class="text-xl font-bold">{{ appName }}</span>
      </div>
      <nav class="flex-1 p-4">
        <RouterLink to="/dashboard" class="block py-2 px-3 rounded hover:bg-slate-700">Dashboard</RouterLink>
      </nav>
      <div class="p-4 border-t border-slate-700">
        <button @click="handleLogout" class="text-sm text-slate-400 hover:text-white">Cerrar sesión</button>
      </div>
    </aside>
    <!-- Main content -->
    <div class="flex-1 flex flex-col overflow-hidden">
      <header class="bg-white shadow-sm h-16 flex items-center px-6">
        <span class="text-gray-600">Bienvenido, {{ user?.first_name }}</span>
      </header>
      <main class="flex-1 overflow-auto p-6">
        <RouterView />
      </main>
    </div>
    <AppToast />
  </div>
</template>
```

#### `mi-proyecto-front/src/components/ui/AppToast.vue`
**Propósito:** Componente que renderiza la lista de toasts del `useNotificationStore`. Posicionado en bottom-right.
```html
<template>
  <div class="fixed bottom-4 right-4 z-50 flex flex-col gap-2">
    <TransitionGroup name="toast">
      <div v-for="toast in toasts" :key="toast.id"
           :class="toastClass(toast.type)"
           class="px-4 py-3 rounded-lg shadow-lg text-sm font-medium min-w-64 max-w-sm">
        {{ toast.message }}
      </div>
    </TransitionGroup>
  </div>
</template>
```

#### `mi-proyecto-front/src/components/ui/AppButton.vue`
**Propósito:** Botón reutilizable con variantes (primary, secondary, danger) y estado loading.
```html
<!-- Props: variant ('primary'|'secondary'|'danger'), loading (boolean), type ('button'|'submit') -->
```

#### `mi-proyecto-front/src/views/auth/LoginView.vue`
**Propósito:** Vista de login. Usa VeeValidate + Zod (`loginSchema`). Maneja los 3 casos: autenticado (redirect dashboard), email no verificado (redirect verify-account), 2FA no implementado en esta iteración.
```html
<!-- Campos: email, password, checkbox "recordarme"
     On submit: useAuthStore().login(email, password, remember)
     Error handling: muestra errores del servidor en un alert, errores de campo bajo el input
     Enlace a /register y /forgot-password -->
```

#### `mi-proyecto-front/src/views/auth/RegisterView.vue`
**Propósito:** Vista de registro. Usa VeeValidate + Zod (`registerSchema`). Al éxito redirige a `/verify-account/:guid`.
```html
<!-- Campos: first_name, last_name, email, password, password_confirmation
     On submit: AuthService.register(payload), luego router.push('/verify-account/' + guid) -->
```

#### `mi-proyecto-front/src/views/auth/VerifyAccountView.vue`
**Propósito:** Verificación de cuenta con código 6 dígitos. Recibe `guid` como prop de ruta. Tiene botón "Reenviar código" con cooldown visual de 2 minutos.
```html
<!-- Props: guid (string)
     Campos: code (6 dígitos, solo números, input type="text" maxlength="6")
     On submit: AuthService.verifyCode(guid, code) → si éxito: router.push('/login')
     Reenviar: AuthService.resendCode(guid), habilita después de 2 minutos -->
```

#### `mi-proyecto-front/src/views/auth/ForgotPasswordView.vue`
**Propósito:** Flujo de 3 pasos en una sola vista (step machine): (1) ingresar email → (2) ingresar código → (3) nueva contraseña. Usa estado interno `step: 1|2|3` y `token`, `guid` en variables reactivas.
```html
<!-- Paso 1 (step=1): campo email → llama forgotPasswordVerifyEmail → guarda token/guid → step=2
     Paso 2 (step=2): campo code → llama forgotPasswordVerifyCode(token, code) → step=3. Botón reenviar.
     Paso 3 (step=3): campos password + password_confirmation → llama forgotPasswordResetPassword(guid, ...) → redirect /login
     Nota: el token del paso 1 se pasa al paso 2. El guid se usa en el paso 3. -->
```
**Nota de diseño:** se unifica ForgotPassword + ResetPassword en una sola vista multi-paso. Esto elimina la dependencia de parámetros de URL para el token (no hay link mágico). Es más simple y coherente con el flujo de código.

#### `mi-proyecto-front/src/views/auth/ResetPasswordView.vue`
**Propósito:** Vista alternativa de reset password (si se navega directamente con `guid` como prop de ruta). Muestra solo el formulario de nueva contraseña. Para el caso de "vengo del paso 2".
```html
<!-- Props: guid (string)
     Campos: password, password_confirmation
     On submit: AuthService.forgotPasswordResetPassword(guid, password, password_confirmation) → redirect /login -->
```

#### `mi-proyecto-front/src/views/dashboard/DashboardView.vue`
**Propósito:** Vista protegida básica. Muestra bienvenida con nombre del usuario y datos del token.
```html
<template>
  <div>
    <h2 class="text-2xl font-semibold text-gray-800">Dashboard</h2>
    <p class="mt-2 text-gray-600">Bienvenido, {{ user?.first_name }} {{ user?.last_name }}</p>
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <!-- 3 cards de placeholder con stats vacíos -->
    </div>
  </div>
</template>
```

#### `mi-proyecto-front/src/i18n/index.ts`
```typescript
import { createI18n } from 'vue-i18n';
export const i18n = createI18n({
    legacy: false,
    locale: 'es',
    fallbackLocale: 'es',
    messages: {},
    missingWarn: import.meta.env.DEV,
});
```

#### `mi-proyecto-front/src/i18n/locales/es/auth.ts`
**Propósito:** Strings de internacionalización para las vistas de auth en español.
```typescript
export default {
    login: { title: 'Iniciar sesión', email: 'Email', password: 'Contraseña', submit: 'Ingresar', register: 'Crear cuenta', forgotPassword: '¿Olvidaste tu contraseña?' },
    register: { title: 'Crear cuenta', firstName: 'Nombre', lastName: 'Apellido', email: 'Email', password: 'Contraseña', passwordConfirmation: 'Confirmar contraseña', submit: 'Registrarse', login: 'Ya tengo cuenta' },
    verifyAccount: { title: 'Verificar cuenta', description: 'Ingresá el código de 6 dígitos que enviamos a tu email.', code: 'Código de verificación', submit: 'Verificar', resend: 'Reenviar código', resendCooldown: 'Podés reenviar en {seconds}s' },
    forgotPassword: {
        step1: { title: 'Recuperar contraseña', description: 'Ingresá tu email para recibir un código.', email: 'Email', submit: 'Enviar código' },
        step2: { title: 'Ingresá el código', description: 'Revisá tu email e ingresá el código de 6 dígitos.', code: 'Código', submit: 'Verificar', resend: 'Reenviar código' },
        step3: { title: 'Nueva contraseña', password: 'Nueva contraseña', passwordConfirmation: 'Confirmar contraseña', submit: 'Cambiar contraseña' },
    },
};
```

#### `mi-proyecto-front/src/i18n/locales/es/global.ts`
```typescript
export default {
    errors: { generic: 'Ocurrió un error inesperado. Intentá nuevamente.', network: 'No se pudo conectar con el servidor.' },
    actions: { save: 'Guardar', cancel: 'Cancelar', close: 'Cerrar', back: 'Volver' },
    breadcrumb: { dashboard: 'Dashboard' },
};
```

#### `mi-proyecto-front/src/main.ts`
```typescript
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import piniaPersist from 'pinia-plugin-persistedstate';
import { router } from '@/router';
import { i18n } from '@/i18n';
import App from '@/App.vue';
import './assets/css/main.css';

async function initApp() {
    const app = createApp(App);
    const pinia = createPinia();
    pinia.use(piniaPersist);

    app.use(pinia);
    app.use(router);
    app.use(i18n);

    app.mount('#app');
}

initApp();
```

#### `mi-proyecto-front/src/App.vue`
```html
<template>
  <RouterView />
</template>
```

---

## Comandos de instalación exactos

### Backend

```bash
# 1. Crear proyecto Laravel 12
composer create-project laravel/laravel mi-proyecto-back "^12.0"
cd mi-proyecto-back

# 2. Instalar Sanctum
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# 3. Instalar l5-repository (patrón Repository)
composer require prettus/l5-repository
php artisan vendor:publish --provider="Prettus\Repository\Providers\RepositoryServiceProvider"

# 4. Instalar dependencias de email (ya incluidas en Laravel, solo verificar)
# php artisan make:mail VerifyAccountEmail — no usar artisan, crear manualmente

# 5. Configurar .env
cp .env.example .env
php artisan key:generate

# 6. Crear la base de datos MySQL
# mysql -u root -e "CREATE DATABASE mi_proyecto;"

# 7. Correr migrations
php artisan migrate

# 8. Verificar que Sanctum está en bootstrap/app.php (Laravel 12)
# Agregar: ->withMiddleware(function (Middleware $middleware) { $middleware->statefulApi(); })

# 9. Iniciar servidor de desarrollo
php artisan serve --port=8000
```

### Frontend

```bash
# 1. Crear proyecto Vite + Vue 3 + TypeScript
npm create vite@latest mi-proyecto-front -- --template vue-ts
cd mi-proyecto-front

# 2. Instalar Tailwind CSS
npm install -D tailwindcss@3 postcss autoprefixer
npx tailwindcss init -p

# 3. Instalar dependencias de la app
npm install pinia pinia-plugin-persistedstate vue-router@4 axios

# 4. Instalar VeeValidate + Zod
npm install vee-validate @vee-validate/zod zod

# 5. Instalar vue-i18n
npm install vue-i18n@9

# 6. Instalar tipos de TypeScript adicionales
npm install -D @types/node

# 7. Configurar alias @ en vite.config.ts
# resolve: { alias: { '@': path.resolve(__dirname, './src') } }

# 8. Iniciar servidor de desarrollo
npm run dev
```

### Configuración de Tailwind CSS (`tailwind.config.js`)
```js
/** @type {import('tailwindcss').Config} */
export default {
    content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
    theme: {
        extend: {
            colors: {
                primary: { DEFAULT: '#1e8665', dark: '#104637', light: '#4C9C82' },
            },
        },
    },
    plugins: [],
};
```

### Archivo CSS base (`src/assets/css/main.css`)
```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
    * { box-sizing: border-box; }
    body { @apply font-sans text-gray-900 antialiased; }
}
```

### `vite.config.ts`
```typescript
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': path.resolve(__dirname, './src') },
    },
    server: {
        port: 5173,
    },
});
```

### `tsconfig.json` (agregar paths)
```json
{
    "compilerOptions": {
        "baseUrl": ".",
        "paths": { "@/*": ["./src/*"] },
        "strict": true
    }
}
```

### `.env` frontend (`.env.local`)
```ini
VITE_APP_NAME="Mi Proyecto"
VITE_API_BASE_URL=http://localhost:8000/api/v1
```

---

## Orden de implementación

### BACKEND

1. Crear el proyecto con `composer create-project laravel/laravel mi-proyecto-back "^12.0"`.

2. Instalar Sanctum y publicar configuración: `composer require laravel/sanctum && php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`.

3. Instalar l5-repository: `composer require prettus/l5-repository && php artisan vendor:publish --provider="Prettus\Repository\Providers\RepositoryServiceProvider"`.

4. Configurar `.env` con credenciales de MySQL y ejecutar `php artisan key:generate`. Crear la base de datos.

5. Reemplazar la migration `create_users_table` con el schema extendido (guid, first_name, last_name, failed_login_attempts, locked_at, verification_code, etc). Eliminar la migration `create_password_reset_tokens_table` de Laravel y crear la nueva `create_password_resets_table` propia.

6. Ejecutar `php artisan migrate`. Verificar que las tablas `users`, `password_resets`, `personal_access_tokens` existen.

7. Crear `app/Traits/HasGuid.php` y `app/Traits/ApiResponseTrait.php` (copiar de Alphinance).

8. Crear `app/Helpers/ResponseHelper.php` (copiar de Alphinance, ajustar `mapDbError` para MySQL).

9. Modificar `app/Http/Controllers/Controller.php` para agregar `use ApiResponseTrait`.

10. Modificar `app/Models/User.php`: agregar traits `HasGuid`, `HasApiTokens`, `Notifiable`; actualizar `$fillable` y `$casts`.

11. Crear `app/Models/PasswordReset.php`.

12. Crear `app/Contracts/Repositories/UserRepositoryInterface.php`.

13. Crear `app/Repositories/BaseRepositoryEloquent.php` (copiar de Alphinance).

14. Crear `app/Repositories/UserRepositoryEloquent.php`.

15. Registrar el binding en `app/Providers/AppServiceProvider.php`: `UserRepositoryInterface → UserRepositoryEloquent`.

16. Crear `app/Services/AuthService.php` con los métodos: `signup`, `login`, `logout`, `verifyCode`, `resendVerificationCode`.

17. Crear `app/Services/ForgotPasswordService.php` con los métodos: `verifyEmail`, `verifyCode`, `resendCode`, `resetPassword`.

18. Crear los 7 FormRequests en `app/Http/Requests/`: `LoginRequest`, `RegisterRequest`, `ForgotPasswordVerifyEmailRequest`, `ForgotPasswordVerifyCodeRequest`, `ForgotPasswordResendCodeRequest`, `ForgotPasswordResetPasswordRequest`, `VerifyCodeRequest`, `ResendVerificationCodeRequest`.

19. Crear `app/Http/Resources/V1/UserResource.php`.

20. Crear `app/Http/Controllers/V1/AuthController.php` y `app/Http/Controllers/V1/PasswordController.php`.

21. Modificar `app/Exceptions/Handler.php` para responder JSON en todas las excepciones cuando el request espera JSON.

22. Modificar `routes/api.php` para usar el loop de includes: `foreach (glob(__DIR__ . '/api/*.php') as $f) require $f;`.

23. Crear `routes/api/auth.php` con las 10 rutas definidas.

24. Configurar CORS en `config/cors.php` o en `bootstrap/app.php`.

25. Crear los Mailables `VerifyAccountEmail` y `ForgotPasswordEmail` con sus vistas Blade.

26. Smoke test manual con curl/Postman:
    - POST /api/v1/auth/register → 200 con guid
    - POST /api/v1/auth/login → 200 con token
    - GET /api/v1/auth/profile con Bearer token → 200 con user
    - POST /api/v1/auth/logout → 200, token revocado

27. Escribir y correr los Feature tests: `RegisterTest`, `LoginTest`, `LogoutTest`, `VerifyAccountTest`, `ForgotPasswordTest`.

### FRONTEND

28. Crear el proyecto con `npm create vite@latest mi-proyecto-front -- --template vue-ts`.

29. Instalar todas las dependencias: `npm install pinia pinia-plugin-persistedstate vue-router@4 axios vee-validate @vee-validate/zod zod vue-i18n@9 && npm install -D tailwindcss@3 postcss autoprefixer @types/node`.

30. Inicializar Tailwind: `npx tailwindcss init -p`. Configurar `tailwind.config.js` con `content` apuntando a `./src/**/*.{vue,ts}`.

31. Configurar `vite.config.ts` con el alias `@` → `./src`.

32. Configurar `tsconfig.json` con `baseUrl` y `paths`.

33. Crear `src/assets/css/main.css` con las directivas de Tailwind. Importar en `main.ts`.

34. Crear `src/config/constants.ts`.

35. Crear `src/i18n/index.ts` y los archivos de locales `src/i18n/locales/es/global.ts` y `src/i18n/locales/es/auth.ts`.

36. Crear `src/types/api/auth.types.ts`.

37. Crear `src/api/http.ts` con la instancia de Axios y los interceptors (request: inyectar Bearer token; response: desempaquetar `success/data/message`, manejar 401/422/500).

38. Crear `src/api/auth.ts` con todos los tipos e interfaces y las funciones de llamada.

39. Crear `src/services/auth.service.ts`.

40. Crear `src/validators/auth.validators.ts` con los schemas Zod.

41. Crear `src/store/auth.ts` con estado, getters y actions (login, register, logout, fetchUser). Configurar `persist: true`.

42. Crear `src/store/notification.ts` con el store de toasts.

43. Crear `src/composables/useAuth.ts` y `src/composables/useNotification.ts`.

44. Crear `src/components/ui/AppToast.vue` y `src/components/ui/AppButton.vue`.

45. Crear `src/layout/AuthLayout.vue` y `src/layout/DashboardLayout.vue`.

46. Crear `src/router/guards.ts` con `authGuard`.

47. Crear `src/router/index.ts` con todas las rutas y aplicar el guard.

48. Crear `src/App.vue` (solo `<RouterView />`).

49. Crear `src/main.ts` con el bootstrap de la app (Pinia + plugin de persistencia, Router, i18n).

50. Crear las 5 vistas de auth: `LoginView.vue`, `RegisterView.vue`, `VerifyAccountView.vue`, `ForgotPasswordView.vue`, `ResetPasswordView.vue`.

51. Crear `DashboardView.vue`.

52. Crear `.env.local` con las variables `VITE_API_BASE_URL` y `VITE_APP_NAME`.

53. Smoke test de integración: iniciar backend (`php artisan serve`) e iniciar frontend (`npm run dev`). Verificar:
    - Navegar a `/login` sin autenticación → ver LoginView en AuthLayout.
    - Hacer login con credenciales válidas → redirigir a `/dashboard`.
    - Refrescar la página → seguir en `/dashboard` (persistencia de sesión).
    - Hacer logout → redirigir a `/login`.
    - Navegar a `/dashboard` sin sesión → redirigir a `/login`.

54. Verificar TypeScript: `npm run type-check` sin errores.

---

## Riesgos y consideraciones

**Riesgo 1 — Bootstrap de Sanctum en Laravel 12.**
Laravel 12 usa el nuevo archivo `bootstrap/app.php` en lugar del middleware stack de `Kernel.php`. Para habilitar `auth:sanctum`, el método `statefulApi()` se registra en `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->statefulApi();
})
```
Si el dev usa el middleware stack de Laravel 11 por error, `auth:sanctum` no funcionará. El dev debe verificar que el middleware `\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class` está configurado correctamente.

**Riesgo 2 — l5-repository y PHP 8.3 / Laravel 12.**
`prettus/l5-repository` puede tener incompatibilidades con PHP 8.3 o Laravel 12 según la versión del package. Verificar en Packagist que la versión instalada soporta PHP 8.3. Si hay conflictos, la alternativa es implementar el patrón Repository manualmente (sin el package), lo que implica reemplazar los pasos 13-14 con clases propias que no extienden `BaseRepository` de Prettus.

**Riesgo 3 — pinia-plugin-persistedstate y hydratación del store.**
El store `auth` persiste en `localStorage` con `persist: true`. El router guard `authGuard` accede a `useAuthStore()` para verificar `isAuthenticated`. Si Pinia no hydrata el store antes de que se ejecute el guard (en el primer render), el usuario puede ser redirigido al login aunque tenga sesión activa. Solución: en `main.ts`, inicializar Pinia **antes** de instalar el router, y asegurarse de que el plugin de persistencia se ejecuta en la inicialización de Pinia.

**Riesgo 4 — Interceptor de Axios importa `useAuthStore` en módulo circular.**
`src/api/http.ts` importa `useAuthStore` de `src/store/auth.ts`, que a su vez puede importar funciones de `src/api/auth.ts`. Si se genera un ciclo de importación, Vite puede no resolverlo correctamente. Solución: en `http.ts`, usar `useAuthStore()` dentro de las funciones del interceptor (no en el módulo top-level), ya que Pinia resuelve el store en tiempo de ejecución.

**Riesgo 5 — CORS en Laravel 12.**
En Laravel 12, el middleware de CORS se configura en `bootstrap/app.php` con `$middleware->api()` o en `config/cors.php`. Si el frontend está en `localhost:5173` y el backend en `localhost:8000`, el origen debe estar en `allowed_origins`. Si `supports_credentials: true` está activado (necesario para cookies), `allowed_origins` no puede ser `['*']`; debe ser el origen exacto. Como esta iteración usa tokens Bearer (no cookies), `supports_credentials` puede ser `false`, lo que simplifica la configuración.

**Riesgo 6 — Flujo de ForgotPasswordView multi-paso y estado perdido al refrescar.**
La vista `ForgotPasswordView.vue` usa estado local reactivo (`step`, `token`, `guid`). Si el usuario refresca la página en el paso 2 o 3, pierde el contexto. Solución en esta iteración: redirigir al paso 1 si se accede directamente a la URL `/forgot-password` sin el contexto (ya es el comportamiento default porque no hay rutas para paso 2/3). Si en el futuro se necesita navegación directa al paso 3, se puede pasar el `guid` como prop de ruta.

**Riesgo 7 — La migration de `password_reset_tokens` de Laravel.**
Laravel 12 incluye por default la migration `0001_01_01_000001_create_password_reset_tokens_table.php`. Si el dev no la elimina antes de correr `php artisan migrate`, se crea la tabla de Laravel Y la nueva tabla `password_resets`. El `ForgotPasswordService` usa `password_resets` (no `password_reset_tokens`), pero tener ambas tablas genera confusión. El plan indica explícitamente eliminar la migration de Laravel antes de migrar.

**Riesgo 8 — VeeValidate con Composition API requiere `useForm` o `<Form>` component.**
VeeValidate v4 tiene dos APIs: Options API (`<Form>`, `<Field>`) y Composition API (`useForm`, `useField`). Las vistas de auth deben usar la misma API consistentemente. El plan recomienda usar los componentes `<Form>` y `<Field>` con el prop `as="input"` para los formularios de auth, que es más simple de implementar y coincide con los ejemplos de la documentación oficial de VeeValidate + Zod.

---

## Pendientes / fuera de alcance

- **2FA (Two-Factor Authentication):** no implementado. Requiere modelo `TwoFactorChallenge`, servicios TOTP, y vistas adicionales. Iteración futura.
- **Refresh token:** el access token no tiene expiración en esta iteración. El refresh token se agrega cuando se define la política de sesiones (duración).
- **Password history:** `storePasswordHistory()` en `UserRepositoryEloquent` es un no-op. Requiere tabla `user_password_histories` en una iteración futura.
- **Rate limiting avanzado:** el throttle actual (`throttle:10,1`) es básico. Un sistema de rate limiting por IP + por usuario para los endpoints sensibles puede requerir un middleware custom.
- **Tests de frontend:** no se especifican tests de componentes Vue en esta iteración. Se pueden agregar con Vitest + Vue Testing Library en una iteración futura.
- **Email templates:** las vistas Blade para los emails son texto plano. Un diseño HTML/CSS de email es una iteración de UX separada.
- **Perfil de usuario:** endpoint `GET /auth/profile` existe pero no hay vista en el frontend para editarlo. Iteración futura.
- **Sesión inactiva / timeout:** Alphinance tiene un plugin de inactividad que cierra sesión automáticamente. No implementado en esta iteración.
