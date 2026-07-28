<?php

namespace App\Modules\Auth\Repositories\Interfaces;

use App\Models\Company;
use App\Models\User;
use App\Modules\Auth\DTOs\RegisterDTO;
use App\Modules\Auth\DTOs\UpdateCompanyDTO;
use App\Modules\Auth\DTOs\WaCredentialsDTO;

interface AuthRepositoryInterface
{
    public function findUserByEmail(string $email): ?User;

    public function findUserById(int $id): ?User;

    public function createCompanyWithOwner(RegisterDTO $dto): User;

    public function findCompanyById(int $id): ?Company;

    public function updateCompany(Company $company, UpdateCompanyDTO $dto): Company;

    public function updateWaCredentials(Company $company, WaCredentialsDTO $dto): Company;

    public function regeneratePrivateToken(Company $company): string;

    public function updateLastLogin(User $user): void;
}
