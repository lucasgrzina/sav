<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeletePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['endpoint' => ['required', 'string']];
    }

    public function messages(): array
    {
        return ['endpoint.required' => 'El endpoint es obligatorio para eliminar la suscripción.'];
    }
}
