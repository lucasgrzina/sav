<?php

namespace App\Notifications\Data;

final readonly class PushContent implements MessageContent
{
    /**
     * @param string $tag colapsa reenvíos de la MISMA alerta en una sola notificación del SO
     *        (el SW hace `tag: payload.tag` + `renotify: Boolean(payload.tag)`) — usar
     *        siempre "alert-{guid_de_la_alerta}", nunca un id numérico (A1).
     * @param string|null $url destino del click, RELATIVO a la raíz de la app y SIN barra
     *        inicial (ej. "programas/{guid}") — la PWA no vive en la raíz del dominio
     *        (se sirve desde /mobile), así que un url absoluto o con "/" inicial rompe el
     *        deep link (A3). Nunca un id numérico.
     * @param array<string,mixed> $data payload adicional que viaja intacto al click del SW;
     *        debe incluir 'requires_confirmation' (bool, snake_case plural — así lo lee el
     *        SW) mapeado desde `alerts.require_confirmation` (A2). Todo lo demás en $data:
     *        siempre guids, nunca ids numéricos.
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $tag,
        public ?string $url = null,
        public array $data = [],
    ) {}
}
