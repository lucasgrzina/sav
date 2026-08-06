<?php

namespace App\Http\Requests\Vets;

use App\Http\Requests\Concerns\ValidatesContactsArray;
use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVetRequest extends FormRequest
{
    use ValidatesContactsArray;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:150'],
            'country_guid'        => ['required', 'string', 'exists:countries,guid'],
            'document_type_guid'  => ['required', 'string', 'exists:document_types,guid'],
            'tax_id'              => ['required', 'string', 'max:50', $this->taxIdRule()],
            'registration_number'         => ['nullable', 'string', 'max:50'],
            'logo_path'                   => ['nullable', 'string', 'max:500'],
            'pdf_title'                   => ['nullable', 'string', 'max:200'],
            'pdf_subtitle'                => ['nullable', 'string', 'max:200'],
            'contacts'                    => ['nullable', 'array'],
            'contacts.*.type'             => ['required', 'string', Rule::in(['email', 'phone', 'whatsapp'])],
            'contacts.*.value'            => ['required', 'string', 'max:200', $this->contactValueFormatRule()],
            'contacts.*.label'            => ['nullable', 'string', 'max:100'],
            'contacts.*.is_primary'       => ['nullable', 'boolean'],
            'contacts.*.use_for_alerts'   => ['nullable', 'boolean'],
        ];
    }

    /**
     * Valida tax_id contra el validation_regex del DocumentType seleccionado.
     * Se ejecuta solo si document_type_guid existe (las reglas se evalúan en orden).
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
            'tax_id.required'             => 'El número de documento fiscal es obligatorio.',
            'tax_id.max'                  => 'El número de documento no puede superar 50 caracteres.',
        ];
    }
}
