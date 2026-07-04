<?php

namespace App\Http\Requests\Vets;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => ['sometimes', 'string', 'max:150'],
            'document_type_guid'  => ['sometimes', 'string', 'exists:document_types,guid'],
            'tax_id'              => ['sometimes', 'string', 'max:50', $this->taxIdRule()],
            'registration_number'         => ['nullable', 'string', 'max:50'],
            'logo_path'                   => ['nullable', 'string', 'max:500'],
            'pdf_title'                   => ['nullable', 'string', 'max:200'],
            'pdf_subtitle'                => ['nullable', 'string', 'max:200'],
            'contacts'                    => ['nullable', 'array'],
            'contacts.*.type'             => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.value'            => ['required', 'string', 'max:200'],
            'contacts.*.label'            => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary'       => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts'   => ['nullable', 'boolean'],
        ];
    }

    /**
     * Si se envía tax_id con document_type_guid, valida contra el regex del tipo.
     * Si no se envía document_type_guid, no aplica la validación de regex
     * (deuda técnica documentada: edge case tax_id sin cambio de tipo).
     */
    private function taxIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $docTypeGuid = $this->input('document_type_guid');

            if (!$docTypeGuid) {
                return;
            }

            $docType = DocumentType::where('guid', $docTypeGuid)->first();

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
            'name.max'                  => 'El nombre no puede superar 150 caracteres.',
            'document_type_guid.exists' => 'El tipo de documento seleccionado no existe.',
            'tax_id.max'                => 'El número de documento no puede superar 50 caracteres.',
        ];
    }
}
