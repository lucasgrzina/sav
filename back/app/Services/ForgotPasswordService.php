<?php

namespace App\Services;

use App\Mail\ForgotPasswordEmail;
use App\Models\PasswordReset;
use App\Models\User;
use App\Repositories\UserRepositoryEloquent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ForgotPasswordService
{
    public function __construct(
        private UserRepositoryEloquent $userRepository,
        private PasswordReset $passwordReset,
    ) {}

    /**
     * Paso 1: verifica que el email existe y envía código de recuperación.
     *
     * @return array{guid: string, email: string, token: string}
     */
    public function verifyEmail(array $data): array
    {
        $user = $this->userRepository->findByEmail($data['email']);

        if (! $user) {
            throw ValidationException::withMessages(['email' => ['Email no registrado.']]);
        }

        ['token' => $token] = $this->createAndSendCode($user);

        return [
            'guid'  => $user->guid,
            'email' => $user->email,
            'token' => $token,
        ];
    }

    /**
     * Paso 2: verifica el código numérico.
     */
    public function verifyCode(array $data): void
    {
        $reset = $this->passwordReset
            ->where('token', $data['token'])
            ->where('code', $data['code'])
            ->first();

        if (! $reset) {
            throw ValidationException::withMessages(['code' => ['Código o token inválido.']]);
        }

        if ($reset->used) {
            throw ValidationException::withMessages(['code' => ['Este código ya fue utilizado.']]);
        }

        $expirationMinutes = (int) config('auth.verification_code_expiration', 10);
        if (now()->isAfter($reset->updated_at->addMinutes($expirationMinutes))) {
            throw ValidationException::withMessages(['code' => ['El código ha expirado. Solicitá uno nuevo.']]);
        }

        $reset->update(['used' => true]);
    }

    /**
     * Reenvía el código al guid del usuario.
     *
     * @return array{guid: string, email: string, token: string}
     */
    public function resendCode(array $data): array
    {
        $user = $this->userRepository->findByGuid($data['guid']);

        if (! $user) {
            throw ValidationException::withMessages(['guid' => ['Usuario no encontrado.']]);
        }

        ['token' => $token] = $this->createAndSendCode($user);

        return [
            'guid'  => $user->guid,
            'email' => $user->email,
            'token' => $token,
        ];
    }

    /**
     * Paso 3: actualiza la contraseña del usuario.
     */
    public function resetPassword(array $data): void
    {
        $user = $this->userRepository->findByGuid($data['guid']);

        if (! $user) {
            throw ValidationException::withMessages(['guid' => ['Usuario no encontrado.']]);
        }

        $this->userRepository->updatePassword($user, $data['password']);
    }

    /**
     * Genera un nuevo código y token, los guarda en password_resets y envía el email.
     *
     * @return array{token: string, code: string}
     */
    private function createAndSendCode(User $user): array
    {
        $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = Str::random(64);

        $this->passwordReset->updateOrCreate(
            ['user_id' => $user->id],
            [
                'token' => $token,
                'code'  => $code,
                'used'  => false,
            ]
        );

        try {
            $expirationMinutes = (int) config('auth.verification_code_expiration', 10);
            Mail::to($user->email)->send(new ForgotPasswordEmail($user, $code, $token, $expirationMinutes));
        } catch (\Exception $e) {
            logger($e->getMessage());
        }

        return ['token' => $token, 'code' => $code];
    }
}
