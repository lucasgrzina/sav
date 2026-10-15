<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property array $resource */
class DashboardAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'generated_at' => $data['generated_at'],
            'vets'         => [
                'active'    => $data['vets']['active'],
                'pending'   => $data['vets']['pending'],
                'suspended' => $data['vets']['suspended'],
                'total'     => $data['vets']['total'],
            ],
            'totals'       => [
                'clients'         => $data['totals']['clients'],
                'establishments'  => $data['totals']['establishments'],
                'animals'         => $data['totals']['animals'],
                'active_programs' => $data['totals']['active_programs'],
            ],
            'sent_last_7d' => array_map(
                fn (array $d) => ['date' => $d['date'], 'count' => $d['count']],
                $data['sent_last_7d'],
            ),
            'delivery_30d' => [
                'rate'       => $data['delivery_30d']['rate'],
                'by_channel' => array_map(
                    fn (array $c) => [
                        'channel' => $c['channel'],
                        'ok'      => $c['ok'],
                        'failed'  => $c['failed'],
                        'rate'    => $c['rate'],
                    ],
                    $data['delivery_30d']['by_channel'],
                ),
            ],
        ];
    }
}
