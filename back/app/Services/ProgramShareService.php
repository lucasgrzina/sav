<?php

namespace App\Services;

use App\Enums\ContactType;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\Export;
use App\Models\Program;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Services\Exports\ExportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Orquesta el envío por WhatsApp del PDF de un programa (DEC-05/DEC-06/DEC-08/DEC-09):
 * lista destinatarios del lado cliente, obtiene/genera el Export PDF vigente y despacha
 * el pipeline Alert/AlertRecipient/DeliverAlertJob ya existente para Notifications.
 */
class ProgramShareService
{
    /** DEC-08: expiración de la URL firmada pública que consume Twilio para descargar el PDF. */
    private const SIGNED_URL_TTL_HOURS = 24;

    public function __construct(
        private readonly ExportService $exportService,
    ) {}

    /**
     * DEC-05: solo personal del lado cliente (UserProfileService::CLIENT_STAFF_ROLES), con
     * el flag has_whatsapp resuelto contra sus contactos habilitados para alertas.
     *
     * @return Collection<int, UserProfile>
     */
    public function listClientRecipients(Program $program): Collection
    {
        $program->loadMissing('managers.role', 'managers.user', 'managers.contacts');

        return $program->managers
            ->filter(fn (UserProfile $manager) => in_array($manager->role->name, UserProfileService::CLIENT_STAFF_ROLES, true))
            ->values()
            ->map(function (UserProfile $manager) {
                $manager->has_whatsapp = $manager->contacts->contains(
                    fn ($contact) => $contact->type === ContactType::Whatsapp && $contact->use_for_alerts,
                );

                return $manager;
            });
    }

    /**
     * DEC-09: reusa el Export PDF vigente (no expirado) del usuario actual para este
     * programa; si no existe, lo genera de forma síncrona (el modal no debe esperar un Job).
     */
    public function getOrCreateShareableExport(Program $program, User $user): Export
    {
        $existing = $this->findExistingShareableExport($program, $user);

        if ($existing !== null) {
            return $existing;
        }

        return $this->exportService->initiate(
            user: $user,
            exportType: ExportType::PROGRAM->value,
            format: ExportFormat::PDF->value,
            filters: ['program_guid' => $program->guid, 'vet_id' => $program->vet_id],
            async: false,
        );
    }

    /**
     * @param string[] $managerProfileGuids
     */
    public function sendPdfToRecipients(Program $program, Export $export, array $managerProfileGuids, int $vetId): Alert
    {
        return DB::transaction(function () use ($program, $export, $managerProfileGuids, $vetId) {
            $recipients = $this->resolveValidRecipients($program, $managerProfileGuids);

            $pdfShareUrl = URL::temporarySignedRoute(
                'programs.shared-pdf',
                now()->addHours(self::SIGNED_URL_TTL_HOURS),
                ['guid' => $export->guid],
            );

            $alert = new Alert([
                'type' => AlertType::ProgramPdfShared,
                'payload' => [
                    'export_guid' => $export->guid,
                    'pdf_share_url' => $pdfShareUrl,
                ],
                'scheduled_at' => now(),
                'status' => 'pending',
                'vet_id' => $vetId,
            ]);
            $alert->subject()->associate($program);
            $alert->save();

            foreach ($recipients as $manager) {
                $recipient = AlertRecipient::create([
                    'alert_id' => $alert->id,
                    'user_profile_id' => $manager->id,
                    'channel' => Channel::Whatsapp,
                    'status' => DeliveryStatus::Pending,
                    'idempotency_key' => Str::uuid()->toString(),
                ]);

                DeliverAlertJob::dispatch($recipient->id);
            }

            $alert->update(['status' => 'dispatched']);

            return $alert;
        });
    }

    private function findExistingShareableExport(Program $program, User $user): ?Export
    {
        return Export::query()
            ->where('user_id', $user->id)
            ->where('type', ExportType::PROGRAM)
            ->where('format', ExportFormat::PDF)
            ->where('status', ExportStatus::COMPLETED)
            ->latest('id')
            ->get()
            ->first(fn (Export $export) => ($export->filters['program_guid'] ?? null) === $program->guid && !$export->isExpired());
    }

    /**
     * @param string[] $managerProfileGuids
     * @return Collection<int, UserProfile>
     */
    private function resolveValidRecipients(Program $program, array $managerProfileGuids): Collection
    {
        $program->loadMissing('managers.role');

        return $program->managers
            ->filter(fn (UserProfile $manager) => in_array($manager->guid, $managerProfileGuids, true))
            ->filter(fn (UserProfile $manager) => in_array($manager->role->name, UserProfileService::CLIENT_STAFF_ROLES, true))
            ->values();
    }
}
