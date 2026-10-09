<?php

namespace App\Services;

use App\Contracts\Repositories\EstablishmentRepositoryInterface;
use App\Contracts\Repositories\ProgramRepositoryInterface;
use App\Contracts\Repositories\ProvinceRepositoryInterface;
use App\Contracts\Repositories\UserProfileRepositoryInterface;
use App\Events\EstablishmentStaffUnlinkedEvent;
use App\Exceptions\EstablishmentStaffMismatchException;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EstablishmentService
{
    public function __construct(
        private EstablishmentRepositoryInterface $establishmentRepository,
        private UserProfileRepositoryInterface $userProfiles,
        private ProgramRepositoryInterface $programs,
        private ProvinceRepositoryInterface $provinces,
    ) {}

    public function listForClient(Client $client, bool $withStaff = true): Collection
    {
        return $this->establishmentRepository->listForClient($client, $withStaff);
    }

    public function create(Client $client, array $data): Establishment
    {
        $data['client_id'] = $client->id;
        return $this->establishmentRepository->create($this->resolveProvince($data))->load('province');
    }

    public function findByGuidForClient(string $guid, Client $client): ?Establishment
    {
        return $this->establishmentRepository->findByGuidForClient($guid, $client);
    }

    public function update(Establishment $establishment, array $data): Establishment
    {
        return $this->establishmentRepository
            ->update($establishment, $this->resolveProvince($data, $establishment))
            ->load('province');
    }

    /**
     * Translates province_guid into province_id and keeps the legacy text column `state` in sync.
     * - province_guid present: province_id is set and state is overwritten with the province name.
     * - province_guid null: province_id is cleared (state stays as sent).
     * - province_guid absent but state sent with a different value: province_id is cleared so both never diverge.
     */
    private function resolveProvince(array $data, ?Establishment $current = null): array
    {
        if (array_key_exists('province_guid', $data)) {
            $guid = $data['province_guid'];
            unset($data['province_guid']);

            $province = $guid ? $this->provinces->findByGuid($guid) : null;

            $data['province_id'] = $province?->id;
            if ($province) {
                $data['state'] = $province->name;
            }
        } elseif ($current && array_key_exists('state', $data) && $data['state'] !== $current->state) {
            $data['province_id'] = null;
        }

        return $data;
    }

    public function destroy(Establishment $establishment): void
    {
        $this->establishmentRepository->destroy($establishment);
    }

    /**
     * Syncs the client staff linked to an establishment.
     *
     * @param string[] $profileGuids desired final state ([] = unlink everyone)
     * @throws EstablishmentStaffMismatchException
     */
    public function syncStaff(Establishment $establishment, array $profileGuids): Establishment
    {
        $guids    = array_values(array_unique($profileGuids));
        $profiles = $this->userProfiles->findManyByGuidsForClient($guids, $establishment->client);

        $valid = $profiles->count() === count($guids)
            && $profiles->every(fn (UserProfile $p) => in_array($p->role?->name, UserProfileService::CLIENT_STAFF_ROLES, true));

        if (!$valid) {
            throw new EstablishmentStaffMismatchException();
        }

        // Pivot sync and manager detach share one transaction so the invariant
        // "client manager => linked to the establishment" can never be broken by a later failure.
        [$detached, $programIds] = DB::transaction(function () use ($establishment, $profiles) {
            $result   = $this->establishmentRepository->syncStaff($establishment, $profiles->pluck('id')->all());
            $detached = array_map('intval', $result['detached']);

            return [
                $detached,
                $this->programs->detachManagersFromActivePrograms($establishment->id, $detached),
            ];
        });

        if ($detached !== []) {
            Log::info('Client staff unlinked from establishment', [
                'establishment_id'  => $establishment->id,
                'profile_ids'       => $detached,
                'affected_programs' => count($programIds),
            ]);

            // After commit: only alert regeneration (isolated per program, never throws).
            event(new EstablishmentStaffUnlinkedEvent($establishment, $detached, $programIds));
        }

        return $establishment->load(['staff.user', 'staff.role']);
    }

    public function isProfileLinked(Establishment $establishment, UserProfile $profile): bool
    {
        return $this->establishmentRepository->hasStaff($establishment, $profile);
    }

    /**
     * Client staff that can be picked as program managers for an establishment:
     * linked and not blocked, ordered by name.
     *
     * @return Collection<int, UserProfile>
     */
    public function listManagerOptions(Establishment $establishment): Collection
    {
        return $this->establishmentRepository
            ->listActiveStaff($establishment)
            ->sortBy(fn (UserProfile $p) => mb_strtolower((string) $p->user?->name))
            ->values();
    }
}
