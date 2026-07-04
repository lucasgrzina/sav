<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordResendCodeRequest;
use App\Http\Requests\ForgotPasswordResetPasswordRequest;
use App\Http\Requests\ForgotPasswordVerifyCodeRequest;
use App\Http\Requests\ForgotPasswordVerifyEmailRequest;
use App\Services\ForgotPasswordService;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function __construct(private ForgotPasswordService $forgotPasswordService) {}

    public function verifyEmail(ForgotPasswordVerifyEmailRequest $request): JsonResponse
    {
        try {
            $result = $this->forgotPasswordService->verifyEmail($request->validated());
            return $this->makeSuccess($result);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function verifyCode(ForgotPasswordVerifyCodeRequest $request): JsonResponse
    {
        try {
            $this->forgotPasswordService->verifyCode($request->validated());
            return $this->makeSuccess([]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function resendCode(ForgotPasswordResendCodeRequest $request): JsonResponse
    {
        try {
            $result = $this->forgotPasswordService->resendCode($request->validated());
            return $this->makeSuccess($result);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    public function resetPassword(ForgotPasswordResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->forgotPasswordService->resetPassword($request->validated());
            return $this->makeSuccess([], 'Contraseña actualizada correctamente.');
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
