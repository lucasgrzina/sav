<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Exceptions\RoleImmutableException;
use App\Exceptions\TechniqueCannotBeDeletedException;
use App\Exceptions\TechniqueChildHasProgramsException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ResponseHelper
{
    public static function successResponse($data, $message = null, int $code = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data'    => $data,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $code);
    }

    public static function errorResponse($errors = null, $message = null, int $code = 400, ?string $errorCode = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ];

        if ($errorCode !== null) {
            $payload['error_code'] = $errorCode;
        }

        return response()->json($payload, $code);
    }

    public static function notFoundResponse($message = null, int $code = 404): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message ?? 'Recurso no encontrado.',
        ], $code);
    }

    public static function makeFromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::errorResponse($e->errors(), $e->getMessage(), 422);
        }

        if ($e instanceof AuthenticationException) {
            return self::errorResponse(null, 'No autenticado.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::errorResponse(null, 'No autorizado.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::notFoundResponse('Recurso no encontrado.');
        }

        if ($e instanceof QueryException) {
            return self::mapDbError($e);
        }

        if ($e instanceof RoleImmutableException) {
            return self::errorResponse(null, $e->getMessage(), 422);
        }

        if ($e instanceof TechniqueCannotBeDeletedException) {
            return self::errorResponse(
                ['reason' => $e->getReason(), 'count' => $e->getCount()],
                $e->getMessage(),
                422,
            );
        }

        if ($e instanceof TechniqueChildHasProgramsException) {
            return self::errorResponse(
                ['conflicts' => $e->getConflicts()],
                $e->getMessage(),
                422,
            );
        }

        if ($e instanceof HttpException) {
            return self::errorResponse(null, $e->getMessage(), $e->getStatusCode());
        }

        return self::errorResponse(null, $e->getMessage() ?: 'Error interno del servidor.', 500);
    }

    private static function mapDbError(QueryException $e): JsonResponse
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $errorCode = $e->errorInfo[1] ?? null;

        // MySQL: 23000 = integrity constraint violation (duplicados y FK violations)
        if ($sqlState === '23000') {
            // Error 1062 = duplicate entry
            if ($errorCode === 1062) {
                return self::errorResponse(null, 'El registro ya existe.', 409);
            }
            // Error 1451/1452 = FK constraint
            if (in_array($errorCode, [1451, 1452])) {
                return self::errorResponse(null, 'No se puede completar la operación por restricciones de integridad.', 422);
            }

            return self::errorResponse(null, 'Conflicto de datos.', 409);
        }

        return self::errorResponse(null, 'Error en la base de datos.', 500);
    }
}
