<?php

namespace App\Modules\Settings\Services;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\Settings\DTOs\SuperAdminCreateCompanyDTO;
use App\Modules\Settings\DTOs\TopUpDTO;
use App\Modules\Settings\DTOs\UpdateCompanyStatusDTO;
use App\Modules\Settings\DTOs\UpdateSettingsDTO;
use App\Modules\Settings\DTOs\WaCredentialsDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;


// ─── SuperAdmin Service ───────────────────────────────────────────────────────
class SuperAdminService
{
    public function dashboard(): array
    {
        return [
            'companies' => [
                'total'     => Company::count(),
                'active'    => Company::where('status','active')->count(),
                'trial'     => Company::where('status','trial')->count(),
                'suspended' => Company::where('status','suspended')->count(),
            ],
            'users'     => ['total' => User::whereNotNull('company_id')->count()],
            'messages'  => ['total' => DB::table('message_logs')->count()],
            'revenue'   => ['total' => DB::table('payment_orders')->where('status','paid')->sum('amount')],
            'recent_companies' => Company::with('plan')->latest()->limit(8)->get(),
        ];
    }

    public function companies(array $filters): LengthAwarePaginator
    {
        return Company::with(['plan','wallet','companyOwner'])
            ->when($filters['search'] ?? null, fn($q) =>
                $q->where('name','like',"%{$filters['search']}%")
                  ->orWhere('email','like',"%{$filters['search']}%")
            )
            ->when($filters['status'] ?? null, fn($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($filters['per_page'] ?? 20);

    }

    public function createCompany(SuperAdminCreateCompanyDTO $dto): Company
    {
        return DB::transaction(function () use ($dto) {
            $ownerRole = Role::where('name','owner')->firstOrFail();

            $company = Company::create([
                'plan_id'       => $dto->planId,
                'name'          => $dto->companyName,
                'slug'          => Str::slug($dto->companyName).'-'.Str::random(4),
                'app_id'        => 'WA_APP_'.strtoupper(Str::random(12)),
                'private_token' => encrypt(Str::random(40)),
                'email'         => $dto->ownerEmail,
                'phone'         => $dto->companyPhone,
                'status'        => 'active',
            ]);

            User::create([
                'company_id' => $company->id,
                'role_id'    => $ownerRole->id,
                'name'       => $dto->ownerName,
                'phone'      => $dto->ownerPhone,
                'email'      => $dto->ownerEmail,
                'password'   => Hash::make($dto->ownerPassword),
                'is_active'  => true,
            ]);

            Wallet::create([
                'company_id' => $company->id,
                'balance'    => $dto->initialBalance,
            ]);

            return $company->fresh(['plan','wallet']);
        });
    }

    public function updateCompany(Company $company, array $data): Company
    {


        $company->update(array_filter([
            'name'    => $data['name']    ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'email'   => $data['email']   ?? null,
            'phone'   => $data['company_phone']   ?? null,
        ], fn($v) => !is_null($v)));


        $ownerData = [
            'name'      => $data['owner_name'] ?? null,
            'phone'     => $data['owner_phone'] ?? null,
            'email'     => $data['owner_email'] ?? null,
            'is_active' => true,
        ];

        if (!empty($data['owner_password'])) {
            $ownerData['password'] = Hash::make($data['owner_password']);
        }

        User::where('company_id', $company->id)
            ->whereHas('role', fn($q) => $q->where('name','owner'))
            ->update(array_filter($ownerData, fn($v) => !is_null($v)));

        return $company->fresh(['plan','wallet']);
    }

    public function updateStatus(Company $company, UpdateCompanyStatusDTO $dto): Company
    {
        $company->update(['status' => $dto->status]);
        return $company->fresh();
    }

    public function deleteCompany(Company $company): void
    {
        $company->delete();
    }

    public function topUp(Company $company, TopUpDTO $dto): array
    {
        $wallet = $company->wallet;
        $before = $wallet->balance;

        $wallet->increment('balance', $dto->amount);
        $wallet->increment('total_purchased', $dto->amount);

        DB::table('wallet_transactions')->insert([
            'company_id'     => $company->id,
            'user_id'        => auth()->id(),
            'type'           => 'credit',
            'amount'         => $dto->amount,
            'balance_before' => $before,
            'balance_after'  => $before + $dto->amount,
            'description'    => $dto->description,
            'reference_type' => 'manual',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return ['balance' => $wallet->fresh()->balance, 'credited' => $dto->amount];
    }

    public function impersonate(Company $company): string
    {
        $owner = $company->users()->whereHas('role', fn($q) => $q->where('name','owner'))->firstOrFail();
        return JWTAuth::fromUser($owner);
    }

    public function plans(): \Illuminate\Database\Eloquent\Collection
    {
        return Plan::withCount('companies')->get();
    }

    public function createPlan(array $data): Plan
    {
        return Plan::create([
            'name'            => $data['name'],
            'messages_limit'  => $data['messages_limit'],
            'price'           => $data['price'],
            'features'        => $data['features'] ?? [],
            'is_active'       => $data['is_active'] ?? true,
        ]);
    }

    public function updatePlan(Plan $plan, array $data): Plan
    {
        $plan->update(array_filter([
            'name'           => $data['name']           ?? null,
            'messages_limit' => $data['messages_limit'] ?? null,
            'price'          => $data['price']          ?? null,
            'features'       => $data['features']       ?? null,
            'is_active'      => $data['is_active']      ?? null,
        ], fn($v) => !is_null($v)));

        return $plan->fresh();
    }

    public function users(array $filters): LengthAwarePaginator
    {
        return User::with(['role','company:id,name'])
            ->when($filters['search'] ?? null, fn($q) =>
                $q->where('name','like',"%{$filters['search']}%")
                  ->orWhere('email','like',"%{$filters['search']}%")
            )
            ->when($filters['company_id'] ?? null, fn($q) => $q->where('company_id', $filters['company_id']))
            ->latest()
            ->paginate($filters['per_page'] ?? 20);
    }

    public function stats(): array
    {
        return [
            'total_companies'   => Company::count(),
            'total_users'       => User::count(),
            'total_messages'    => DB::table('message_logs')->count(),
            'total_contacts'    => DB::table('contacts')->count(),
            'total_leads'       => DB::table('leads')->whereNull('deleted_at')->count(),
            'total_campaigns'   => DB::table('campaigns')->whereNull('deleted_at')->count(),
            'total_revenue_inr' => DB::table('payment_orders')->where('status','paid')->sum('amount'),
            'messages_today'    => DB::table('message_logs')->whereDate('created_at',now())->count(),
        ];
    }
}
