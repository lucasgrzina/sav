<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class LoginRequest extends FormRequest
{
    public ?User $user = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El email es requerido.',
            'email.email'    => 'El email no tiene un formato válido.',
            'password.required' => 'La contraseña es requerida.',
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->email) {
            $this->user = User::where('email', $this->email)->first();
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            if (! $this->user) {
                $validator->errors()->add('email', 'Credenciales inválidas.');
                return;
            }

            $maxAttempts = (int) config('auth.max_failed_login_attempts', 3);

            // Verificar si la cuenta está bloqueada
            if ($this->user->locked_at) {
                $validator->errors()->add('email', 'Tu cuenta está bloqueada. Contactá al soporte.');
                return;
            }

            // Verificar contraseña
            if (! Hash::check($this->password, $this->user->password)) {
                $this->user->failed_login_attempts += 1;

                if ($this->user->failed_login_attempts >= $maxAttempts) {
                    $this->user->locked_at = now();
                    $this->user->save();
                    $validator->errors()->add('email', 'Tu cuenta fue bloqueada por demasiados intentos fallidos.');
                    return;
                }

                $this->user->save();
                $remaining = $maxAttempts - $this->user->failed_login_attempts;
                $validator->errors()->add('password', "Contraseña incorrecta. Te quedan {$remaining} intento(s).");
            }
        });
    }
}
