<?php

namespace App\Services;

use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class ContactService
{
    public function __construct(
        private ContactRepositoryInterface $contactRepository,
    ) {}

    /**
     * Crea un contacto. Si is_primary = true, limpia los demás del mismo
     * (contactable, type) antes de crear.
     *
     * @param Model $contactable  Instancia de Vet o UserProfile
     * @param array $data         Campos validados: type, label?, value, is_primary, use_for_alerts
     */
    public function create(Model $contactable, array $data): Contact
    {
        $type = $this->resolveContactableType($contactable);

        if (!empty($data['is_primary'])) {
            $this->contactRepository->clearPrimaryForType(
                $type,
                $contactable->id,
                $data['type'],
            );
        }

        return $this->contactRepository->create([
            'contactable_type' => $type,
            'contactable_id'   => $contactable->id,
            'type'             => $data['type'],
            'label'            => $data['label'] ?? null,
            'value'            => $data['value'],
            'is_primary'       => $data['is_primary'] ?? false,
            'use_for_alerts'   => $data['use_for_alerts'] ?? false,
        ]);
    }

    /**
     * Actualiza un contacto. Si se está seteando is_primary = true,
     * limpia los demás del mismo (contactable, type) excepto el actual.
     *
     * @param Contact $contact  Instancia a actualizar
     * @param array   $data     Campos a modificar (parcial)
     */
    public function update(Contact $contact, array $data): Contact
    {
        $isPrimaryBeingSet = isset($data['is_primary']) && $data['is_primary'] === true;

        if ($isPrimaryBeingSet) {
            $this->contactRepository->clearPrimaryForType(
                $contact->contactable_type,
                $contact->contactable_id,
                $data['type'] ?? $contact->type->value,
                $contact->id,
            );
        }

        return $this->contactRepository->update($contact, $data);
    }

    public function destroy(Contact $contact): void
    {
        $this->contactRepository->destroy($contact);
    }

    /**
     * Sincroniza los contactos de un contactable con el array de items recibido.
     *
     * Reglas del diff:
     *   - Item con guid existente y perteneciente al contactable → actualizar.
     *   - Item con guid desconocido o que no pertenece al contactable → crear como nuevo.
     *   - Item sin guid → crear nuevo.
     *   - Contactos existentes del contactable cuyo guid no aparece en $items → eliminar.
     *
     * NOTA multi-tenant: este método opera sobre $contactable->contacts() que es una relación
     * polimórfica del propio contactable. La seguridad de tenant depende de que el contactable
     * haya sido resuelto con el scope de tenant correcto antes de llamar este método.
     *
     * @param Model  $contactable  Instancia Eloquent con relación contacts() (HasContacts)
     * @param array  $items        Array de arrays con shape:
     *                             { guid?: string, type, value, label?, is_primary, use_for_alerts }
     */
    public function syncContacts(Model $contactable, array $items): void
    {
        // 1. Cargar contactos actuales indexados por guid
        $existing = $contactable->contacts()->get()->keyBy('guid');

        $incomingGuids = [];

        foreach ($items as $item) {
            $guid    = $item['guid'] ?? null;
            $contact = $guid ? ($existing->get($guid) ?? null) : null;

            if ($contact) {
                // Contacto existente y perteneciente a este contactable → actualizar.
                // Se construye el array explícitamente (sin array_filter) para que label=null
                // sea procesado correctamente y no se descarte como falsy.
                $incomingGuids[] = $guid;
                $this->update($contact, [
                    'type'           => $item['type'],
                    'value'          => $item['value'],
                    'label'          => $item['label'] ?? null,
                    'is_primary'     => $item['is_primary'] ?? false,
                    'use_for_alerts' => $item['use_for_alerts'] ?? false,
                ]);
            } else {
                // Sin guid, guid desconocido, o guid de otro contactable → crear nuevo.
                $created = $this->create($contactable, [
                    'type'           => $item['type'],
                    'value'          => $item['value'],
                    'label'          => $item['label'] ?? null,
                    'is_primary'     => $item['is_primary'] ?? false,
                    'use_for_alerts' => $item['use_for_alerts'] ?? false,
                ]);
                $incomingGuids[] = $created->guid;
            }
        }

        // 2. Eliminar los contactos existentes no incluidos en el array recibido
        foreach ($existing as $existingGuid => $contact) {
            if (!in_array($existingGuid, $incomingGuids, true)) {
                $this->destroy($contact);
            }
        }
    }

    /**
     * Busca un contacto por guid y verifica que pertenece al contactable dado.
     * Retorna null si no existe o si el contactable no coincide.
     */
    public function findByGuidForContactable(string $guid, Model $contactable): ?Contact
    {
        $contact = $this->contactRepository->findByGuid($guid);

        if (!$contact) {
            return null;
        }

        if ($contact->contactable_type !== $this->resolveContactableType($contactable)
            || $contact->contactable_id !== $contactable->id) {
            return null;
        }

        return $contact;
    }

    /**
     * Retorna el alias del morphMap para el contactable, o el FQCN como fallback.
     * Garantiza consistencia entre lo que se escribe en DB y lo que se compara.
     */
    private function resolveContactableType(Model $contactable): string
    {
        $morphMap = Relation::morphMap();
        return array_search(get_class($contactable), $morphMap) ?: get_class($contactable);
    }
}
