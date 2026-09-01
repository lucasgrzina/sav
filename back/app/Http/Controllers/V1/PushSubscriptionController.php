<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeletePushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Http\Resources\V1\PushSubscriptionResource;
use App\Services\PushSubscriptionService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;

class PushSubscriptionController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private PushSubscriptionService $service) {}

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        try {
            $subscription = $this->service->subscribe($request->user(), $request->validated());
            return $this->makeSuccess(new PushSubscriptionResource($subscription));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        try {
            $deleted = $this->service->unsubscribe($request->user(), $request->validated('endpoint'));
            return $deleted
                ? response()->json(null, 204)
                : $this->makeNotFound('La suscripción no existe o ya fue eliminada.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
