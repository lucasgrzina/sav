<?php

namespace App\Services;

use App\Contracts\Repositories\SystemSettingRepositoryInterface;
use App\Mail\VerifyAccountEmail;
use App\Models\User;
use App\Repositories\UserRepositoryEloquent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private UserRepositoryEloquent $userRepository,
        private SystemSettingRepositoryInterface $systemSettingRepository,
    ) {}

    /**
     * Registra un nuevo usuario y envía email de verificación con código 6 dígitos.
     *
     * @return array{guid: string, email: string}
     */
    public function signup(array $data): array
    {
        $expirationMinutes = (int) config('auth.verification_code_expiration', 10);
        $code  = $this->generateVerificationCode();
        $token = Str::random(64);

        DB::beginTransaction();
        try {
            $user = $this->userRepository->create([
                'first_name'                   => $data['first_name'],
                'last_name'                    => $data['last_name'],
                'name'                         => $data['first_name'] . ' ' . $data['last_name'],
                'email'                        => $data['email'],
                'password'                     => Hash::make($data['password']),
                'verification_code'            => $code,
                'verification_code_expires_at' => now()->addMinutes($expirationMinutes),
                'verification_link_token'      => $token,
                'verification_link_expires_at' => now()->addMinutes($expirationMinutes),
                'last_verification_email_sent_at' => now(),
            ]);

            $this->sendVerificationEmail($user, $code, $token, $expirationMinutes);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'guid'  => $user->guid,
            'email' => $user->email,
        ];
    }

    /**
     * Autentica al usuario. Retorna token de acceso o flag de verificación pendiente.
     *
     * @return array{access_token?: string, user: array, must_verify_account: bool}
     */
    public function login(User $user): array
    {
        if (! $user->email_verified_at) {
            return [
                'must_verify_account' => true,
                'user'                => $this->formatUserPublic($user),
            ];
        }

        // Revocar tokens anteriores
        $user->tokens()->delete();

        $expirationMinutes = (int) config('sanctum.expiration', 1440) ?: 1440;
        $token = $user->createToken('api-access', ['*'], now()->addMinutes($expirationMinutes));

        // Actualizar último login y resetear intentos fallidos
        $user->last_login_at          = now();
        $user->failed_login_attempts  = 0;
        $user->locked_at              = null;
        $user->save();

        // Verificar expiración de password
        $mustChangePassword = $this->isPasswordExpired($user);

        return [
            'access_token'         => $token->plainTextToken,
            'user'                 => $this->formatUserPublic($user),
            'must_verify_account'  => false,
            'must_change_password' => $mustChangePassword,
        ];
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
        $user = $this->userRepository->findByGuid($data['guid']);

        if (! $user) {
            throw ValidationException::withMessages(['guid' => ['Usuario no encontrado.']]);
        }

        if ($user->email_verified_at) {
            throw ValidationException::withMessages(['code' => ['La cuenta ya fue verificada.']]);
        }

        if ($user->verification_code !== $data['code']) {
            throw ValidationException::withMessages(['code' => ['El código ingresado es incorrecto.']]);
        }

        if ($user->verification_code_expires_at && now()->isAfter($user->verification_code_expires_at)) {
            throw ValidationException::withMessages(['code' => ['El código ha expirado. Solicitá uno nuevo.']]);
        }

        $user->email_verified_at              = now();
        $user->verification_code              = null;
        $user->verification_code_expires_at   = null;
        $user->verification_link_token        = null;
        $user->verification_link_expires_at   = null;
        $user->save();
    }

    /**
     * Reenvía el código de verificación al email con cooldown de 2 minutos.
     *
     * @return array{guid: string, email: string}
     */
    public function resendVerificationCode(array $data): array
    {
        $user = $this->userRepository->findByGuid($data['guid']);

        if (! $user) {
            throw ValidationException::withMessages(['guid' => ['Usuario no encontrado.']]);
        }

        if ($user->email_verified_at) {
            throw ValidationException::withMessages(['guid' => ['La cuenta ya fue verificada.']]);
        }

        // Cooldown de 2 minutos
        if ($user->last_verification_email_sent_at && now()->diffInSeconds($user->last_verification_email_sent_at) < 120) {
            $seconds = 120 - now()->diffInSeconds($user->last_verification_email_sent_at);
            throw ValidationException::withMessages([
                'guid' => ["Debés esperar {$seconds} segundos antes de reenviar el código."],
            ]);
        }

        $expirationMinutes = (int) config('auth.verification_code_expiration', 10);
        $code  = $this->generateVerificationCode();
        $token = Str::random(64);

        $user->verification_code              = $code;
        $user->verification_code_expires_at   = now()->addMinutes($expirationMinutes);
        $user->verification_link_token        = $token;
        $user->verification_link_expires_at   = now()->addMinutes($expirationMinutes);
        $user->last_verification_email_sent_at = now();
        $user->save();

        $this->sendVerificationEmail($user, $code, $token, $expirationMinutes);

        return [
            'guid'  => $user->guid,
            'email' => $user->email,
        ];
    }

    /** @return array{id, guid, first_name, last_name, email, last_login_at, roles, permissions} */
    public function formatUserPublic(User $user): array
    {
        $user->loadMissing('roles', 'permissions');

        return [
            'id'            => $user->id,
            'guid'          => $user->guid,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'roles'         => $user->roles->map(fn ($r) => ['guid' => $r->guid, 'name' => $r->name]),
            'permissions'   => $user->getAllPermissions()->pluck('name'),
        ];
    }

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
            $user->verification_link_token      = null;
            $user->verification_link_expires_at = null;

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

    private function isPasswordExpired(User $user): bool
    {
        $months = (int) $this->systemSettingRepository->getValue('password_expiration_months', 0);

        if ($months <= 0) {
            return false;
        }

        if ($user->password_changed_at === null) {
            // Si nunca se cambió el password, usar created_at como referencia
            return now()->isAfter($user->created_at->addMonths($months));
        }

        return now()->isAfter($user->password_changed_at->addMonths($months));
    }

    private function generateVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendVerificationEmail(User $user, string $code, string $token, int $minutes): void
    {
        try {
            Mail::to($user->email)->send(new VerifyAccountEmail($user, $code, $token, $minutes));
        } catch (\Exception $e) {
            logger($e->getMessage());
        }
    }
}
