<?php

namespace App\Modules\Auth\Services;

use App\Models\Company;
use App\Models\User;
use App\Modules\Auth\DTOs\AuthResultDTO;
use App\Modules\Auth\DTOs\LoginDTO;
use App\Modules\Auth\DTOs\RegisterDTO;
use App\Modules\Auth\DTOs\TokenDTO;
use App\Modules\Auth\DTOs\UpdateCompanyDTO;
use App\Modules\Auth\DTOs\WaCredentialsDTO;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Repositories\Interfaces\AuthRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthService
{
    public function __construct(
        private readonly AuthRepositoryInterface $authRepository,
    ) {}

    // ─── Login ────────────────────────────────────────────────────────────────
    public function login(LoginDTO $dto): AuthResultDTO
    {
        $user = $this->authRepository->findUserByEmail($dto->email);

        if (!$user || !Hash::check($dto->password, $user->password)) {
            throw AuthException::invalidCredentials();
        }

        if (!$user->is_active) {
            throw AuthException::accountDeactivated();
        }

        if ($user->company && $user->company->status === 'suspended') {
            throw AuthException::companySuspended();
        }

        $token = JWTAuth::fromUser($user);
        $this->authRepository->updateLastLogin($user);

        return new AuthResultDTO(
            token: TokenDTO::fromJwt($token),
            user:  $user,
        );
    }

    // ─── Register ────────────────────────────────────────────────────────────
    public function register(RegisterDTO $dto): AuthResultDTO
    {
        $user  = $this->authRepository->createCompanyWithOwner($dto);
        $token = JWTAuth::fromUser($user);

        return new AuthResultDTO(
            token: TokenDTO::fromJwt($token),
            user:  $user,
        );
    }

    // ─── Get authenticated user ───────────────────────────────────────────────
    public function me(): User
    {
        $user = $this->authRepository->findUserById(auth()->id());

        if (!$user) {
            throw AuthException::userNotFound();
        }

        return $user;
    }

    // ─── Refresh JWT token ────────────────────────────────────────────────────
    public function refresh(): TokenDTO
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return TokenDTO::fromJwt($newToken);
        } catch (\Exception $e) {
            throw AuthException::tokenRefreshFailed();
        }
    }

    // ─── Logout ───────────────────────────────────────────────────────────────
    public function logout(): void
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Exception) {
            // Already invalid — safe to ignore
        }
    }

    // ─── Update company profile ───────────────────────────────────────────────
    public function updateCompany(Company $company, UpdateCompanyDTO $dto): Company
    {
        return $this->authRepository->updateCompany($company, $dto);
    }

    // ─── Update WA credentials ────────────────────────────────────────────────
    public function updateWaCredentials(Company $company, WaCredentialsDTO $dto): Company
    {
        return $this->authRepository->updateWaCredentials($company, $dto);
    }

    // ─── Regenerate private token ─────────────────────────────────────────────
    public function regenerateToken(Company $company): string
    {
        return $this->authRepository->regeneratePrivateToken($company);
    }
}
