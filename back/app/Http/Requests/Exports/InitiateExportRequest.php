<?php

namespace App\Http\Requests\Exports;

use App\Enums\ExportFormat;
use App\Enums\ExportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class InitiateExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La policy se aplica en el controller
    }

    public function rules(): array
    {
        return [
            'type'              => ['required', 'string', new Enum(ExportType::class)],
            'format'            => ['required', 'string', new Enum(ExportFormat::class)],
            'async'             => ['sometimes', 'boolean'],
            'filters'           => ['sometimes', 'array'],
            'filters.search'    => ['sometimes', 'string', 'max:100'],
            'filters.status'    => ['sometimes', 'string', 'in:verified,unverified,locked'],
            'filters.date_from' => ['sometimes', 'date_format:Y-m-d'],
            'filters.date_to'   => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:filters.date_from'],
            'columns'           => ['sometimes', 'array'],
            'columns.*'         => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required'                            => 'El tipo de exportación es requerido.',
            'type.Illuminate\Validation\Rules\Enum'   => 'El tipo de exportación no es válido.',
            'format.required'                          => 'El formato de exportación es requerido.',
            'format.Illuminate\Validation\Rules\Enum' => 'El formato de exportación no es válido.',
            'filters.date_to.after_or_equal'           => 'La fecha final debe ser posterior a la inicial.',
        ];
    }
}
