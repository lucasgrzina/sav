<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResendVerificationCodeRequest;
use App\Http\Requests\VerifyCodeRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->signup($request->validated());
            return $this->makeSuccess($result, 'Registro exitoso. Revisá tu email para verificar la cuenta.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login($request->user);
            return $this->makeSuccess($result);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request);
            return $this->makeSuccess(null, 'Sesión cerrada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->user()->load('roles', 'permissions');
            return $this->makeSuccess(new UserResource($user));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function verifyCode(VerifyCodeRequest $request): JsonResponse
    {
        try {
            $this->authService->verifyCode($request->validated());
            return $this->makeSuccess([], 'Email verificado correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function resendCode(ResendVerificationCodeRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->resendVerificationCode($request->validated());
            return $this->makeSuccess($result);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function acceptInvitation(AcceptInvitationRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->acceptInvitation($request->validated());
            return $this->makeSuccess($result, 'Bienvenido. Tu cuenta fue activada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
