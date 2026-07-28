<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\DTOs\LoginDTO;
use App\Modules\Auth\DTOs\RegisterDTO;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Resources\AuthResource;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    // ─── POST /auth/login ─────────────────────────────────────────────────────
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            LoginDTO::fromRequest($request->validated())
        );

        return AuthResource::make($result)
            ->response()
            ->setStatusCode(200);
    }

    // ─── POST /auth/register ──────────────────────────────────────────────────
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register(
            RegisterDTO::fromRequest($request->validated())
        );

        return AuthResource::make($result)
            ->response()
            ->setStatusCode(201);
    }

    // ─── GET /auth/me ─────────────────────────────────────────────────────────
    public function me(): JsonResponse
    {
        $user = $this->authService->me();

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    // ─── POST /auth/refresh ───────────────────────────────────────────────────
    public function refresh(): JsonResponse
    {
        $token = $this->authService->refresh();

        return response()->json([
            'access_token' => $token->accessToken,
            'token_type'   => $token->tokenType,
            'expires_in'   => $token->expiresIn,
        ]);
    }

    // ─── POST /auth/logout ────────────────────────────────────────────────────
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
