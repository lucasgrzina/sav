<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array $resource
 *
 * Presentation only. Never exposes internal ids, `failure_reason`, provider data or unmasked contact
 * data: destinations come from the masked hint stored at send time. Alert blocks are null when the
 * user lacks programs.alerts.read.
 */
class VetHomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'generated_at'      => $data['generated_at'],
            'timezone'          => $data['timezone'],
            'upcoming_programs' => array_map(fn (array $p) => [
                'guid'             => $p['guid'],
                'client'           => $p['client'],
                'establishment'    => $p['establishment'],
                'technique'        => $p['technique'],
                'protocol'         => $p['protocol'],
                'next_target_date' => $p['next_target_date'],
            ], $data['upcoming_programs']),
            'recent_alerts'     => $data['recent_alerts'] === null
                ? null
                : array_map(fn (array $a) => $this->alert($a, false), $data['recent_alerts']),
            'problem_alerts'    => $data['problem_alerts'] === null
                ? null
                : array_map(fn (array $a) => $this->alert($a, true), $data['problem_alerts']),
        ];
    }

    /** @return array<string, mixed> */
    private function alert(array $a, bool $problem): array
    {
        $out = [
            'key'                => $a['key'],
            'type'               => $a['type'],
            'state'              => $a['state'],
            'recipient'          => $a['recipient'],
            'task_description'   => $a['task_description'],
            'scheduled_at_local' => $a['scheduled_at_local'],
            'sent_at'            => $a['sent_at'],
            'sent_at_local'      => $a['sent_at_local'],
            'destination_masked' => $a['destination_masked'],
            'program'            => $a['program'],
        ];

        if ($problem) {
            $out['failure_code'] = $a['failure_code'];
        }

        return $out;
    }
}
