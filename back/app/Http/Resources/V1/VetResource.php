<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'                => $this->guid,
            'name'                => $this->name,
            'slug'                => $this->slug,
            'tax_id'              => $this->tax_id,
            'registration_number' => $this->registration_number,
            'logo_path'           => $this->logo_path,
            'pdf_title'           => $this->pdf_title,
            'pdf_subtitle'        => $this->pdf_subtitle,
            'validated_at'        => $this->validated_at?->toISOString(),
            'suspended_at'        => $this->suspended_at?->toISOString(),
            'is_active'           => $this->validated_at !== null && $this->suspended_at === null,
            'country'             => new CountryResource($this->whenLoaded('country')),
            'document_type'       => new DocumentTypeResource($this->whenLoaded('documentType')),
            'validated_by'        => $this->whenLoaded('validatedBy', fn () => [
                'guid' => $this->validatedBy->guid,
                'name' => $this->validatedBy->name,
            ]),
            'contacts'            => ContactResource::collection($this->whenLoaded('contacts')),
            'created_at'          => $this->created_at?->toISOString(),
        ];
    }
}
