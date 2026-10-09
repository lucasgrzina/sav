<?php

namespace App\Http\Requests\Establishments;

use Illuminate\Foundation\Http\FormRequest;

class GeocodeAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address'  => ['nullable', 'string', 'max:255', 'required_without:city'],
            'city'     => ['nullable', 'string', 'max:100', 'required_without:address'],
            'state'    => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'address.required_without' => 'Ingresá al menos la dirección o la localidad.',
            'city.required_without'    => 'Ingresá al menos la dirección o la localidad.',
            'address.max'              => 'La dirección no puede superar 255 caracteres.',
            'city.max'                 => 'La localidad no puede superar 100 caracteres.',
            'state.max'                => 'La provincia no puede superar 100 caracteres.',
            'zip_code.max'             => 'El código postal no puede superar 20 caracteres.',
        ];
    }
}
