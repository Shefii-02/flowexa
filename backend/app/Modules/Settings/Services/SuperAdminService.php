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
                'plan_id'          => $dto->planId,
                'name'             => $dto->companyName,
                'slug'             => Str::slug($dto->companyName).'-'.Str::random(4),
                'app_id'           => 'WA_APP_'.strtoupper(Str::random(12)),
                'private_token'    => encrypt(Str::random(40)),
                'email'            => $dto->ownerEmail,
                'phone'            => $dto->companyPhone,
                'status'           => 'active',
                'industry_template'=> $dto->businessType,
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

            app(\App\Modules\Auth\Support\CompanyStarterKit::class)->seed($company, $dto->businessType);

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

    // ── Billing / subscription overview ───────────────────────────────────────
    public function billing(): array
    {
        $grace = (int) config('billing.grace_days', 3);

        // Monthly-recurring-revenue: every active company's plan price
        // normalised to a monthly figure.
        $activeCompanies = Company::where('status', 'active')
            ->whereNotNull('plan_id')
            ->with('plan')
            ->get();

        $monthly = fn (Plan $p) => (int) ($p->duration_months ?? 1) > 0
            ? (float) $p->price / (int) ($p->duration_months ?? 1)
            : (float) $p->price;

        $mrr = 0.0;
        $byPlan = [];
        foreach ($activeCompanies as $c) {
            if (! $c->plan) continue;
            $m = $monthly($c->plan);
            $mrr += $m;
            $byPlan[$c->plan->id] ??= ['plan' => $c->plan->name, 'companies' => 0, 'mrr' => 0.0];
            $byPlan[$c->plan->id]['companies']++;
            $byPlan[$c->plan->id]['mrr'] += $m;
        }

        $thisMonth = DB::table('payment_orders')->where('status', 'paid')
            ->where('created_at', '>=', now()->startOfMonth())->sum('amount');
        $lastMonth = DB::table('payment_orders')->where('status', 'paid')
            ->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()])
            ->sum('amount');

        return [
            'mrr'  => round($mrr, 2),
            'arr'  => round($mrr * 12, 2),
            'companies' => [
                'total'     => Company::count(),
                'active'    => Company::where('status', 'active')->count(),
                'trial'     => Company::where('status', 'trial')->count(),
                'expired'   => Company::where('status', 'expired')->count(),
                'suspended' => Company::where('status', 'suspended')->count(),
            ],
            'revenue' => [
                'this_month' => (float) $thisMonth,
                'last_month' => (float) $lastMonth,
                'all_time'   => (float) DB::table('payment_orders')->where('status', 'paid')->sum('amount'),
            ],
            'by_plan' => array_values(collect($byPlan)->map(fn ($r) => [
                'plan'      => $r['plan'],
                'companies' => $r['companies'],
                'mrr'       => round($r['mrr'], 2),
            ])->sortByDesc('mrr')->values()->all()),
            'recent_orders' => DB::table('payment_orders as po')
                ->leftJoin('companies as c', 'c.id', '=', 'po.company_id')
                ->orderByDesc('po.created_at')->limit(15)
                ->get(['po.id', 'po.amount', 'po.status', 'po.messages_credit', 'po.razorpay_order_id', 'po.razorpay_payment_id', 'po.created_at', 'c.name as company'])
                ->map(fn ($o) => [
                    'id'         => $o->id,
                    'company'    => $o->company,
                    'amount'     => (float) $o->amount,
                    'status'     => $o->status,
                    'kind'       => $o->messages_credit > 0 ? 'wallet_topup' : 'plan',
                    'reference'  => $o->razorpay_payment_id ?: $o->razorpay_order_id,
                    'created_at' => $o->created_at,
                ]),
            'upcoming_expiries' => Company::query()
                ->whereNotNull('plan_expires_at')
                ->whereIn('status', ['active', 'trial'])
                ->whereBetween('plan_expires_at', [now(), now()->addDays(14)])
                ->with('plan:id,name')
                ->orderBy('plan_expires_at')
                ->get(['id', 'name', 'plan_id', 'plan_expires_at', 'status'])
                ->map(fn ($c) => [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'plan'       => $c->plan?->name,
                    'expires_at' => $c->plan_expires_at,
                    'days_left'  => (int) ceil(now()->floatDiffInDays($c->plan_expires_at, false)),
                    'status'     => $c->status,
                ]),
            'recent_changes' => \App\Models\CompanyPlan::with(['plan:id,name', 'company:id,name'])
                ->latest()->limit(15)->get()
                ->map(fn ($cp) => [
                    'id'         => $cp->id,
                    'company'    => $cp->company?->name,
                    'plan'       => $cp->plan?->name,
                    'status'     => $cp->status,
                    'amount'     => (float) $cp->amount_paid,
                    'duration'   => $cp->duration_type,
                    'starts_at'  => $cp->starts_at,
                    'expires_at' => $cp->expires_at,
                ]),
            'grace_days' => $grace,
        ];
    }
}
