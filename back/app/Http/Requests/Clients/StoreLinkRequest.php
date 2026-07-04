<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class StoreLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
        // No hay body — el guid del client viene como parámetro de ruta.
        // La validación de existencia del client se hace en el controller via ClientService.
    }
}
