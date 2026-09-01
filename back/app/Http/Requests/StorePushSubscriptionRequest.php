<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uuid' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['required', 'string', 'max:8192', 'url'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            'device_label' => ['nullable', 'string', 'max:255'],
            'updated_at' => ['nullable', 'date'],
            'user_id' => ['prohibited'], // regla dura del brief: nunca aceptar suplantación de usuario
        ];
    }

    public function messages(): array
    {
        return [
            'endpoint.required' => 'El endpoint de la suscripción es obligatorio.',
            'endpoint.url' => 'El endpoint debe ser una URL válida.',
            'keys.p256dh.required' => 'Falta la clave p256dh de la suscripción.',
            'keys.auth.required' => 'Falta la clave auth de la suscripción.',
            'user_id.prohibited' => 'No se permite especificar el usuario de la suscripción.',
        ];
    }
}
