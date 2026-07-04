<?php

namespace App\Http\Requests\Clients;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                      => ['required', 'string', 'max:150'],
            'country_guid'              => ['required', 'string', 'exists:countries,guid'],
            'document_type_guid'        => ['required', 'string', 'exists:document_types,guid'],
            'tax_id'                    => ['required', 'string', 'max:50', $this->taxIdRule()],
            'address'                   => ['nullable', 'string', 'max:200'],
            'city'                      => ['nullable', 'string', 'max:100'],
            'state'                     => ['nullable', 'string', 'max:100'],
            'zip_code'                  => ['nullable', 'string', 'max:20'],

            // Contactos iniciales opcionales
            'contacts'                  => ['nullable', 'array', 'max:10'],
            'contacts.*.type'           => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.label'          => ['nullable', 'string', 'max:100'],
            'contacts.*.value'          => ['required', 'string', 'max:200'],
            'contacts.*.is_primary'     => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Reutiliza exactamente el mismo patrón que StoreVetRequest::taxIdRule().
     */
    private function taxIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $docTypeGuid = $this->input('document_type_guid');
            $docType     = DocumentType::where('guid', $docTypeGuid)->first();

            if (!$docType || !$docType->validation_regex) {
                return;
            }

            $pattern = '/' . $docType->validation_regex . '/';

            if (!preg_match($pattern, $value)) {
                $fail("El formato del {$docType->name} es inválido.");
            }
        };
    }

    public function messages(): array
    {
        return [
            'name.required'               => 'El nombre es obligatorio.',
            'name.max'                    => 'El nombre no puede superar 150 caracteres.',
            'country_guid.required'       => 'El país es obligatorio.',
            'country_guid.exists'         => 'El país seleccionado no existe.',
            'document_type_guid.required' => 'El tipo de documento es obligatorio.',
            'document_type_guid.exists'   => 'El tipo de documento seleccionado no existe.',
            'tax_id.required'             => 'El identificador fiscal es obligatorio.',
            'tax_id.max'                  => 'El identificador fiscal no puede superar 50 caracteres.',
            'contacts.max'                => 'No se pueden agregar más de 10 contactos iniciales.',
            'contacts.*.type.required'    => 'Cada contacto debe tener un tipo.',
            'contacts.*.type.in'          => 'Tipo de contacto inválido. Valores: email, phone, whatsapp.',
            'contacts.*.value.required'   => 'Cada contacto debe tener un valor.',
        ];
    }
}
