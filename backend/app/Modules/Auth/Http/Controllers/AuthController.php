<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\DTOs\LoginDTO;
use App\Modules\Auth\DTOs\RegisterDTO;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Resources\AuthResource;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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


    public function profile(): JsonResponse
    {
        return response()->json(['user' => auth()->user()->load('role', 'company')]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name'       => ['sometimes', 'string', 'max:100'],
            'phone'      => ['sometimes', 'string', 'max:25'],
            'language'   => ['sometimes', 'string', 'max:5'],
            'department' => ['sometimes', 'string', 'max:60'],
        ]);
        auth()->user()->update($d);
        return response()->json(['user' => auth()->user()->fresh()]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $d = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        if (!Hash::check($d['current_password'], auth()->user()->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }
        auth()->user()->update(['password' => Hash::make($d['password'])]);
        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $request->email)->first();
        if (!$user) return response()->json(['message' => 'If this email exists, a reset link has been sent.']);

        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        // Send reset link via email or WhatsApp
        // Mail::to($user->email)->send(new PasswordResetMail($token, $user));
        return response()->json(['message' => 'Password reset link sent to your email.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $d = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $record = DB::table('password_reset_tokens')->where('email', $d['email'])->first();
        if (!$record || !Hash::check($d['token'], $record->token)) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }
        if (now()->diffInMinutes($record->created_at) > 60) {
            return response()->json(['message' => 'Reset token has expired. Request a new one.'], 422);
        }
        User::where('email', $d['email'])->update(['password' => Hash::make($d['password'])]);
        DB::table('password_reset_tokens')->where('email', $d['email'])->delete();
        return response()->json(['message' => 'Password reset successfully. You can now login.']);
    }
}
