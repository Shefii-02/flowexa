<?php

namespace App\Modules\Auth\Repositories;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\Auth\DTOs\RegisterDTO;
use App\Modules\Auth\DTOs\UpdateCompanyDTO;
use App\Modules\Auth\DTOs\WaCredentialsDTO;
use App\Modules\Auth\Repositories\Interfaces\AuthRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthRepository implements AuthRepositoryInterface
{
    // ─── Find user by email ───────────────────────────────────────────────────
    public function findUserByEmail(string $email): ?User
    {
        return User::with(['role', 'company.wallet', 'company.plan'])
            ->where('email', $email)
            ->first();
    }

    // ─── Find user by id ──────────────────────────────────────────────────────
    public function findUserById(int $id): ?User
    {
        return User::with(['role', 'company.wallet', 'company.plan'])
            ->find($id);
    }

    // ─── Create company + owner + wallet in one transaction ───────────────────
    public function createCompanyWithOwner(RegisterDTO $dto): User
    {
        return DB::transaction(function () use ($dto) {

            $trialPlan = Plan::where('name', 'Trial')->first();
            $ownerRole = Role::where('name', 'owner')->firstOrFail();

            // 1. Company
            $company = Company::create([
                'plan_id'       => $trialPlan?->id,
                'name'          => $dto->companyName,
                'slug'          => $this->uniqueSlug($dto->companyName),
                'app_id'        => $this->generateAppId(),
                'private_token' => encrypt(Str::random(40)),
                'email'         => $dto->email,
                'status'        => 'trial',
                'trial_ends_at' => now()->addDays(14),
            ]);

            // 2. Owner user
            $user = User::create([
                'company_id' => $company->id,
                'role_id'    => $ownerRole->id,
                'name'       => $dto->ownerName,
                'email'      => $dto->email,
                'phone'      => $dto->phone,
                'password'   => Hash::make($dto->password),
                'is_active'  => true,
            ]);

            // 3. Wallet with 1000 free messages
            Wallet::create([
                'company_id'          => $company->id,
                'balance'             => 1000,
                'free_quota_reset_at' => now()->addMonth(),
            ]);

            return $user->load(['role', 'company.wallet', 'company.plan']);
        });
    }

    // ─── Find company ─────────────────────────────────────────────────────────
    public function findCompanyById(int $id): ?Company
    {
        return Company::with(['plan', 'wallet'])->find($id);
    }

    // ─── Update company profile ───────────────────────────────────────────────
    public function updateCompany(Company $company, UpdateCompanyDTO $dto): Company
    {
        $data = array_filter([
            'name'     => $dto->name,
            'email'    => $dto->email,
            'phone'    => $dto->phone,
            'website'  => $dto->website,
            'settings' => $dto->settings,
        ], fn($v) => !is_null($v));

        $company->update($data);
        return $company->fresh(['plan', 'wallet']);
    }

    // ─── Update WhatsApp credentials ──────────────────────────────────────────
    public function updateWaCredentials(Company $company, WaCredentialsDTO $dto): Company
    {
        $company->update([
            'wa_phone_id'      => $dto->waPhoneId,
            'wa_access_token'  => encrypt($dto->waAccessToken),
            'wa_business_id'   => $dto->waBusinessId,
        ]);

        return $company->fresh();
    }

    // ─── Regenerate private API token ─────────────────────────────────────────
    public function regeneratePrivateToken(Company $company): string
    {
        $raw = Str::random(40);
        $company->update(['private_token' => encrypt($raw)]);
        return $raw; // return raw only once — never stored as plain text
    }

    // ─── Update last login timestamp ──────────────────────────────────────────
    public function updateLastLogin(User $user): void
    {
        $user->update(['last_login_at' => now()]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (Company::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function generateAppId(): string
    {
        do {
            $id = 'WA_APP_' . strtoupper(Str::random(12));
        } while (Company::where('app_id', $id)->exists());

        return $id;
    }
}
