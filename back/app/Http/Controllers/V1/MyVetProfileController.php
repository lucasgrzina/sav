<?php

namespace App\Http\Controllers\V1;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\UpdateMyVetProfileRequest;
use App\Http\Resources\V1\UserProfileResource;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyVetProfileController extends Controller
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ContactService          $contactService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            $profile = $request->attributes->get('current_profile');

            return $this->makeSuccess(
                new UserProfileResource($profile->load(['user', 'role', 'contacts']))
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function update(UpdateMyVetProfileRequest $request): JsonResponse
    {
        try {
            $profile = $request->attributes->get('current_profile');
            $data    = $request->validated();

            $this->userRepository->update($profile->user, [
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'name'       => $data['first_name'] . ' ' . $data['last_name'],
            ]);

            $this->contactService->syncContacts($profile, $data['contacts'] ?? []);

            return $this->makeSuccess(
                new UserProfileResource($profile->load(['user', 'role', 'contacts'])),
                'Tu perfil fue actualizado correctamente.',
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
