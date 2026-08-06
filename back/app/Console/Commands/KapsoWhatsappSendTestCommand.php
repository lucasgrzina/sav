<?php

namespace App\Console\Commands;

use App\Notifications\Data\OutboundMessage;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TemplateContent;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Exceptions\NotificationConfigurationException;
use App\Notifications\Gateways\Kapso\KapsoWhatsappGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exercises the Kapso gateway in isolation: no Alert, no AlertRecipient, no queue. It
 * shortens the diagnosis loop from "create a whole program in the UI" to one command, and
 * it prints the wamid so the delivery-status webhooks can be correlated afterwards.
 */
class KapsoWhatsappSendTestCommand extends Command
{
    protected $signature = 'kapso:send-test
        {phone : Destino, se normaliza a solo dígitos (ej. 5491134290838)}
        {--type=program.created : AlertType cuyo template se envía}
        {--text= : Envía este texto libre en lugar del template}
        {--name=Lucas : Valor del placeholder {{1}} (nombre del destinatario)}';

    protected $description = 'Envía un WhatsApp de prueba por Kapso e imprime el wamid para correlacionar los webhooks.';

    public function handle(): int
    {
        $phone = preg_replace('/\D+/', '', (string) $this->argument('phone')) ?? '';

        if ($phone === '') {
            $this->error('El teléfono no contiene dígitos.');

            return Command::FAILURE;
        }

        $name = (string) $this->option('name');
        $text = (string) $this->option('text');

        if ($text !== '') {
            $content = new TextContent($text);
            $this->warn('Texto libre: Meta solo lo entrega dentro de la ventana de 24h abierta por el destinatario.');
        } else {
            $type = AlertType::tryFrom((string) $this->option('type'));

            if ($type === null) {
                $this->error('AlertType inválido. Disponibles: ' . implode(', ', array_column(AlertType::cases(), 'value')));

                return Command::FAILURE;
            }

            $content = new TemplateContent($type, $this->variablesFor($type, $name));
        }

        try {
            $gateway = app(KapsoWhatsappGateway::class);
            $result = $gateway->send(new OutboundMessage(
                recipient: new Recipient(userId: 0, phone: $phone, name: $name, channel: Channel::Whatsapp),
                content: $content,
                channel: Channel::Whatsapp,
                idempotencyKey: Str::uuid()->toString(),
            ));
        } catch (NotificationConfigurationException $e) {
            $this->error('Error de configuración (definitivo, no se reintenta): ' . $e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->error('Fallo transitorio (en producción la cola reintentaría): ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($result->status === DeliveryStatus::Failed) {
            $this->error('Rechazo definitivo de Kapso/Meta: ' . $result->failureReason);

            return Command::FAILURE;
        }

        $this->info("Aceptado por Kapso. wamid: {$result->providerMessageId}");
        $this->line('Los webhooks de estado de este mensaje quedan en whatsapp_webhook_events.provider_message_id.');

        return Command::SUCCESS;
    }

    /**
     * Mirrors the placeholders each MessageBuilder produces, so a mismatch between the
     * template in Kapso and the real message shows up here instead of in production.
     *
     * @return array<string, string>
     */
    private function variablesFor(AlertType $type, string $name): array
    {
        return match ($type) {
            AlertType::ProgramTaskDue => [
                '1' => $name,
                '2' => 'Sincronización IATF',
                '3' => 'hoy toca retirar el dispositivo',
            ],
            default => [
                '1' => $name,
                '2' => 'Sincronización IATF',
            ],
        };
    }
}
