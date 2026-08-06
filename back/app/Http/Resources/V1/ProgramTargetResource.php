<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'        => $this->guid,
            'target_date' => $this->target_date->toDateString(),
            'animals'     => $this->animals->map(fn ($a) => [
                'guid' => $a->guid,
                'rp'   => $a->rp,
                'name' => $a->name,
            ]),
            // DEC-15/DEC-16: presente SOLO en detalle (ProgramService::projectTargetTasks anota
            // projected_tasks sobre el modelo en memoria). Nunca en el listado.
            'tasks'       => $this->when(isset($this->projected_tasks), fn () => $this->projected_tasks),
        ];
    }
}
