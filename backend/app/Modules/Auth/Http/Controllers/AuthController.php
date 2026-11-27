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
        // The web form carries a text captcha; the mobile app (X-Client: mobile) is exempt.
        if (CaptchaController::enabled() && $request->header('X-Client') !== 'mobile'
            && !CaptchaController::verify($request->input('captcha_id'), $request->input('captcha'))) {
            return response()->json([
                'message'    => 'Captcha is incorrect or expired. Please try again.',
                'error_code' => 'captcha_invalid',
            ], 422);
        }

        $result = $this->authService->login(
            LoginDTO::fromRequest($request->validated())
        );

        $isMobile = $request->header('X-Client') === 'mobile';
        if (!$result->user->canUseChannel($isMobile ? 'mobile' : 'portal')) {
            return response()->json([
                'message'    => $isMobile ? 'Mobile app access is disabled for your account.' : 'Web portal access is disabled for your account.',
                'error_code' => $isMobile ? 'mobile_access_disabled' : 'portal_access_disabled',
            ], 403);
        }

        // Phone-app sign-ins are tracked per device and capped per user (companies.max_devices_per_user) — the same rule as the QR / PIN
        // login. Over the cap → 409 + the list of phones to remove, then the app repeats this call. No device id sent = an older app build:
        // not tracked, exactly as before.
        if ($isMobile) {
            $admitted = app(\App\Support\MobileDevices::class)->admit($request, $result->user);
            if ($admitted instanceof JsonResponse) {
                return $admitted;
            }
            if (is_string($admitted)) {
                $result = new \App\Modules\Auth\DTOs\AuthResultDTO(
                    token: \App\Modules\Auth\DTOs\TokenDTO::fromJwt($admitted),
                    user:  $result->user,
                );
            }
        }

        // Web portal sign-ins are tracked per browser and capped per user
        // (companies.max_web_devices_per_user). Over the cap → 409 + the list of browsers to remove.
        if (!$isMobile) {
            $limited = app(\App\Support\WebSessions::class)->admit($request, $result->user);
            if ($limited instanceof JsonResponse) {
                return $limited;
            }
            $result = new \App\Modules\Auth\DTOs\AuthResultDTO(
                token: \App\Modules\Auth\DTOs\TokenDTO::fromJwt($limited),
                user:  $result->user,
            );
        }

        return AuthResource::make($result)
            ->response()
            ->setStatusCode(200);
    }

    // ─── POST /auth/mobile-devices/remove — free a phone slot when the phone-app limit sheet is shown ──
    public function removeMobileDevice(Request $request): JsonResponse
    {
        return app(\App\Support\MobileDevices::class)->remove($request);
    }

    // ─── POST /auth/web-sessions/remove — free a browser slot when the limit page is shown ──
    public function removeWebSession(Request $request): JsonResponse
    {
        return app(\App\Support\WebSessions::class)->remove($request);
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
    public function logout(Request $request): JsonResponse
    {
        // Signing out of a device also drops it from "Your registered devices" (push), so logged-out browsers/phones don't pile up there.
        if ($tok = (string) $request->input('fcm_token', '')) {
            \App\Models\PushToken::where('user_id', auth()->id())->where('fcm_token', $tok)->update(['is_active' => false]);
        }

        app(\App\Support\WebSessions::class)->release();
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
            'avatar'     => ['sometimes', 'file', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('avatar')) {
            // into the Media Library → "Profile Photos" folder; the old photo is removed
            $d['avatar'] = app(\App\Modules\Auth\Support\ProfilePhotoStorage::class)->store(auth()->user(), $request->file('avatar'));
        }

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
        // diffInMinutes is signed in Carbon 3 (now → past is negative), so compare instants instead: tokens older than 60 min are rejected
        if (!$record->created_at || \Illuminate\Support\Carbon::parse($record->created_at)->lt(now()->subMinutes(60))) {
            return response()->json(['message' => 'Reset token has expired. Request a new one.'], 422);
        }
        User::where('email', $d['email'])->update(['password' => Hash::make($d['password'])]);
        DB::table('password_reset_tokens')->where('email', $d['email'])->delete();
        return response()->json(['message' => 'Password reset successfully. You can now login.']);
    }
}
