<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use App\Helpers\ResponseHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait ApiResponseTrait
{
    public function makeSuccess($data, $message = null, $code = 200): JsonResponse
    {
        return ResponseHelper::successResponse($data, $message, $code);
    }

    public function makeError($errors = null, $message = null, $code = 400, ?string $errorCode = null): JsonResponse
    {
        return ResponseHelper::errorResponse($errors, $message, $code, $errorCode);
    }

    public function makeNotFound($message = null, $code = 404): JsonResponse
    {
        return ResponseHelper::notFoundResponse($message, $code);
    }

    public function makeFromException($exception): JsonResponse
    {
        return ResponseHelper::makeFromException($exception);
    }

    public function makeSuccessPagination(
        LengthAwarePaginator $paginator,
        ?string $resource = null
    ): JsonResponse {
        $data = $resource
            ? $resource::collection($paginator->items())
            : $paginator->items();
    
        return $this->makeSuccess([
            'data'         => $data,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
