<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Http\Resources\V1\ContactResource;
use App\Services\ClientService;
use App\Services\ContactService;
use App\Services\UserProfileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        private ContactService     $contactService,
        private UserProfileService $userProfileService,
        private ClientService      $clientService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $contactable = $this->resolveContactable($request);
            $contacts    = $contactable->contacts()->get();

            return $this->makeSuccess(ContactResource::collection($contacts));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        try {
            $contactable = $this->resolveContactable($request);
            $contact     = $this->contactService->create($contactable, $request->validated());

            return $this->makeSuccess(new ContactResource($contact), 'Contacto creado correctamente.', 201);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateContactRequest $request): JsonResponse
    {
        try {
            $guid        = $request->route('guid');
            $contactable = $this->resolveContactable($request);
            $contact     = $this->contactService->findByGuidForContactable($guid, $contactable);

            if (!$contact) {
                return $this->makeNotFound('Contacto no encontrado.');
            }

            $contact = $this->contactService->update($contact, $request->validated());

            return $this->makeSuccess(new ContactResource($contact), 'Contacto actualizado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $guid        = $request->route('guid');
            $contactable = $this->resolveContactable($request);
            $contact     = $this->contactService->findByGuidForContactable($guid, $contactable);

            if (!$contact) {
                return $this->makeNotFound('Contacto no encontrado.');
            }

            $this->contactService->destroy($contact);

            return $this->makeSuccess(null, 'Contacto eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * Resuelve el contactable (Vet, UserProfile o Client) según los parámetros de ruta.
     * Si existe el parámetro 'profile' en la ruta, verifica que pertenezca al tenant actual.
     * Si existe el parámetro 'client' en la ruta, verifica que pertenezca al tenant actual.
     * Lanza abort(404) si el recurso no existe o pertenece a otro tenant.
     */
    private function resolveContactable(Request $request): Model
    {
        $vet         = $request->attributes->get('current_vet');
        $profileGuid = $request->route('profile');
        $clientGuid  = $request->route('client');

        if ($profileGuid !== null) {
            $profile = $this->userProfileService->findByGuidForVet($profileGuid, $vet);

            if (!$profile) {
                abort(404, 'Perfil no encontrado en esta veterinaria.');
            }

            return $profile;
        }

        if ($clientGuid !== null) {
            $client = $this->clientService->findByGuidForVet($clientGuid, $vet);

            if (!$client) {
                abort(404, 'Cliente no encontrado en esta veterinaria.');
            }

            return $client;
        }

        return $vet;
    }
}
