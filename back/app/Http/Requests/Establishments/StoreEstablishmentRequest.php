<?php

namespace App\Http\Requests\Establishments;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:150'],
            'renspa'    => ['nullable', 'string', 'max:50'],
            'address'   => ['nullable', 'string', 'max:255'],
            'city'      => ['nullable', 'string', 'max:100'],
            'state'     => ['nullable', 'string', 'max:100'],
            'zip_code'  => ['nullable', 'string', 'max:20'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'El nombre del establecimiento es obligatorio.',
            'name.max'         => 'El nombre no puede superar 150 caracteres.',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.between'=> 'La longitud debe estar entre -180 y 180.',
        ];
    }
}
