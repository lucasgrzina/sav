<?php

namespace App\Notifications\Services;

use App\Notifications\Enums\Channel;
use App\Notifications\Models\OptOut;
use App\Notifications\Models\WhatsappWebhookEvent;
use App\Notifications\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Interprets one `whatsapp.message.received` event. Deliberately narrow: opt-out / opt-in
 * keyword detection only. Confirmations and support-conversation forwarding are out of scope
 * (see the ticket) and reuse this same inbound event when they land.
 */
final class RecordInboundOptOut
{
    private const OPT_OUT_KEYWORDS = ['baja', 'stop', 'cancelar', 'desuscribir', 'desuscribirme'];
    private const OPT_IN_KEYWORDS = ['alta', 'start', 'suscribir', 'suscribirme'];

    public function apply(WhatsappWebhookEvent $event): string
    {
        $from = data_get($event->payload, 'message.from');
        $body = data_get($event->payload, 'message.text.body');

        if (! is_string($from) || $from === '' || ! is_string($body)) {
            // Non-text messages (image, sticker, button reply...) have no text.body: nothing
            // to act on, and it is not an error — most inbound traffic will look like this.
            return 'mensaje entrante sin from/body aplicable';
        }

        $phone = PhoneNumber::normalize($from);
        $keyword = self::normalizeKeyword($body);

        return match (true) {
            in_array($keyword, self::OPT_OUT_KEYWORDS, true) => $this->optOut($phone),
            in_array($keyword, self::OPT_IN_KEYWORDS, true) => $this->optIn($phone),
            default => 'mensaje entrante sin palabra clave reconocida',
        };
    }

    private function optOut(string $phone): string
    {
        OptOut::firstOrCreate(['phone' => $phone, 'channel' => Channel::Whatsapp->value]);

        return 'opt-out registrado';
    }

    private function optIn(string $phone): string
    {
        $deleted = OptOut::where('phone', $phone)->where('channel', Channel::Whatsapp->value)->delete();

        return $deleted > 0 ? 'opt-in: baja revertida' : 'opt-in: no había baja previa';
    }

    private static function normalizeKeyword(string $body): string
    {
        return (string) Str::of($body)->trim()->lower()->ascii()->trim(" \t\n\r\0\x0B.,!¡?¿");
    }
}
