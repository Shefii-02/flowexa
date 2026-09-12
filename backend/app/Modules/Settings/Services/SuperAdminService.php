<?php

namespace App\Modules\Settings\Services;

use App\Models\ApiRequestLog;
use App\Models\Company;
use App\Models\ErrorLog;
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

            $isTrial = $dto->status === 'trial';

            $company = Company::create([
                'plan_id'          => $dto->planId,
                'name'             => $dto->companyName,
                'slug'             => Str::slug($dto->companyName).'-'.Str::random(4),
                'app_id'           => 'WA_APP_'.strtoupper(Str::random(12)),
                'private_token'    => encrypt(Str::random(40)),
                'email'            => $dto->companyEmail ?: $dto->ownerEmail,
                'phone'            => $dto->companyPhone,
                'website'          => $dto->website,
                'status'           => $isTrial ? 'trial' : 'active',
                'trial_ends_at'    => $isTrial ? now()->addDays($dto->trialDays ?: 14) : null,
                'max_devices_per_user' => $dto->maxDevicesPerUser ?: 2,
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
            'email'   => $data['company_email'] ?? $data['email'] ?? null,
            'phone'   => $data['company_phone']   ?? null,
            'website' => $data['website'] ?? null,
            'max_devices_per_user' => $data['max_devices_per_user'] ?? null,
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

    /**
     * Full Company-table config update for the superadmin "Company Config" page.
     * Secret fields (wa_access_token, wa_chat_token) are only overwritten when a
     * genuinely new, non-empty value is submitted — the config page always shows
     * them masked, so an untouched masked value is never round-tripped back in.
     */
    public function updateCompanyConfig(Company $company, array $data): Company
    {
        $update = [];

        foreach (['name', 'email', 'phone', 'website', 'wa_phone_id', 'wa_business_id',
                  'meta_app_id', 'wa_profile_id', 'wa_webhook_token', 'suspended_reason'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f] === '' ? null : $data[$f];
            }
        }

        foreach (['storage_limit_bytes', 'max_devices_per_user', 'waha_max_sessions',
                  'waha_max_webhooks', 'waha_media_limit_mb'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && $data[$f] !== '') {
                $update[$f] = (int) $data[$f];
            }
        }

        foreach (['waha_enabled', 'wa_auth_enabled'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = (bool) $data[$f];
            }
        }

        if (!empty($data['industry_template'])) {
            $update['industry_template'] = $data['industry_template'];
        }

        if (!empty($data['status'])) {
            $update['status'] = $data['status'];
        }

        foreach (['trial_ends_at', 'plan_expires_at', 'wa_chat_token_expires_at'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f] ? \Carbon\Carbon::parse($data[$f]) : null;
            }
        }

        // Secrets: only overwrite when a real new value came in.
        if (!empty($data['wa_access_token'])) {
            $update['wa_access_token'] = encrypt($data['wa_access_token']);
        }
        if (!empty($data['wa_chat_token'])) {
            $update['wa_chat_token'] = $data['wa_chat_token']; // stored plain — sent as the X-API-Key header
        }

        if (array_key_exists('settings', $data)) {
            $decoded = is_array($data['settings']) ? $data['settings'] : json_decode((string) $data['settings'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $update['settings'] = $decoded;
            }
        }

        $company->update($update);

        return $company->fresh();
    }

    // ── Observability: API request log / activity feed ──────────────────────────

    public function apiLogs(array $filters): LengthAwarePaginator
    {
        $mutating = ['POST', 'PUT', 'PATCH', 'DELETE'];

        return ApiRequestLog::with(['company:id,name', 'user:id,name'])
            ->when($filters['company_id'] ?? null, fn ($q, $c) => $q->where('company_id', $c))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
            ->when($filters['activity_only'] ?? null, fn ($q) => $q->whereIn('method', $mutating))
            ->when(isset($filters['is_error']) && $filters['is_error'] !== '', fn ($q) => $q->where('is_error', filter_var($filters['is_error'], FILTER_VALIDATE_BOOLEAN)))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($qq) => $qq->where('path', 'like', "%{$s}%")->orWhere('route_name', 'like', "%{$s}%")))
            ->when($filters['from'] ?? null, fn ($q, $f) => $q->whereDate('created_at', '>=', $f))
            ->when($filters['to'] ?? null, fn ($q, $t) => $q->whereDate('created_at', '<=', $t))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 40), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function apiLogStats(array $filters): array
    {
        $range = (int) ($filters['days'] ?? 7);
        $since = now()->subDays($range)->startOfDay();

        $base = fn () => ApiRequestLog::where('created_at', '>=', $since)
            ->when($filters['company_id'] ?? null, fn ($q, $c) => $q->where('company_id', $c));

        $total  = $base()->count();
        $errors = $base()->where('is_error', true)->count();
        $avgMs  = (float) ($base()->avg('duration_ms') ?? 0);

        $byDay = $base()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total, SUM(is_error) as errors, AVG(duration_ms) as avg_ms')
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => $r->day, 'total' => (int) $r->total, 'errors' => (int) $r->errors, 'avg_ms' => round((float) $r->avg_ms, 1)]);

        $topCompanies = $base()
            ->whereNotNull('company_id')
            ->selectRaw('company_id, COUNT(*) as total, SUM(is_error) as errors, AVG(duration_ms) as avg_ms')
            ->groupBy('company_id')->orderByDesc('total')->limit(10)
            ->with('company:id,name')->get()
            ->map(fn ($r) => [
                'company_id' => $r->company_id,
                'company'    => $r->company?->name,
                'total'      => (int) $r->total,
                'errors'     => (int) $r->errors,
                'avg_ms'     => round((float) $r->avg_ms, 1),
            ]);

        $topRoutes = $base()
            ->whereNotNull('route_name')
            ->selectRaw('route_name, COUNT(*) as total, AVG(duration_ms) as avg_ms')
            ->groupBy('route_name')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['route_name' => $r->route_name, 'total' => (int) $r->total, 'avg_ms' => round((float) $r->avg_ms, 1)]);

        $statusBreakdown = $base()
            ->selectRaw('status_code, COUNT(*) as total')
            ->groupBy('status_code')->orderByDesc('total')->get()
            ->map(fn ($r) => ['status_code' => $r->status_code, 'total' => (int) $r->total]);

        return [
            'range_days'      => $range,
            'total_requests'  => $total,
            'error_count'     => $errors,
            'error_rate'      => $total > 0 ? round($errors / $total * 100, 2) : 0.0,
            'avg_duration_ms' => round($avgMs, 1),
            'requests_today'  => ApiRequestLog::whereDate('created_at', now())
                ->when($filters['company_id'] ?? null, fn ($q, $c) => $q->where('company_id', $c))->count(),
            'by_day'          => $byDay,
            'top_companies'   => $topCompanies,
            'top_routes'      => $topRoutes,
            'status_breakdown'=> $statusBreakdown,
        ];
    }

    // ── Observability: company-scoped error log ──────────────────────────────────

    public function errorLogs(array $filters): LengthAwarePaginator
    {
        return ErrorLog::with(['company:id,name', 'user:id,name'])
            ->where('source', $filters['source'] ?? ErrorLog::SOURCE_LARAVEL)
            ->when($filters['company_id'] ?? null, fn ($q, $c) => $q->where('company_id', $c))
            ->when($filters['exception'] ?? null, fn ($q, $e) => $q->where('exception_class', 'like', "%{$e}%"))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('message', 'like', "%{$s}%"))
            ->when($filters['from'] ?? null, fn ($q, $f) => $q->whereDate('created_at', '>=', $f))
            ->when($filters['to'] ?? null, fn ($q, $t) => $q->whereDate('created_at', '<=', $t))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 40), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function errorLog(int $id): ErrorLog
    {
        return ErrorLog::with(['company:id,name', 'user:id,name'])->findOrFail($id);
    }

    /** Deletes stored (Laravel/frontend) error_logs rows for one source. Returns rows deleted. */
    public function clearErrorLogs(string $source): int
    {
        return ErrorLog::where('source', $source)->delete();
    }

    // ── Observability: raw Laravel log file viewer ────────────────────────────────

    public function systemLogFiles(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];

        return collect($files)
            ->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'modified_at' => date('c', filemtime($f))])
            ->sortByDesc('modified_at')->values()->all();
    }

    /**
     * Tail-reads a log file without loading the whole thing into memory — only
     * the last ~1MB is scanned. Entries are split on the "[YYYY-MM-DD HH:MM:SS]"
     * marker Laravel's formatter starts every entry with, so a multi-line stack
     * trace stays attached to the entry it belongs to instead of being chopped
     * into separate "lines".
     */
    public function systemLog(string $file, int $lines = 300, ?string $search = null): array
    {
        $safe = basename($file);
        $path = storage_path("logs/{$safe}");

        if (!str_ends_with($safe, '.log') || !is_file($path)) {
            return ['file' => $safe, 'entries' => [], 'error' => 'File not found.'];
        }

        $maxBytes = 1_000_000;
        $size = filesize($path);
        $handle = fopen($path, 'r');

        if ($size > $maxBytes) {
            fseek($handle, -$maxBytes, SEEK_END);
            fgets($handle); // discard a possibly-partial first line
        }
        $content = stream_get_contents($handle);
        fclose($handle);

        $entries = preg_split('/(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/', (string) $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($search) {
            $entries = array_values(array_filter($entries, fn ($e) => stripos($e, $search) !== false));
        }

        $entries = array_map('trim', array_slice($entries, -$lines));

        return ['file' => $safe, 'size' => $size, 'entries' => $entries];
    }

    // ── Observability: failed queue jobs ──────────────────────────────────────────

    public function failedJobs(array $filters): LengthAwarePaginator
    {
        return DB::table('failed_jobs')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($qq) => $qq->where('payload', 'like', "%{$s}%")->orWhere('exception', 'like', "%{$s}%")
            ))
            ->when($filters['queue'] ?? null, fn ($q, $qu) => $q->where('queue', $qu))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 30), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /** Re-queues a failed job (and removes it from failed_jobs) via the same mechanism as `queue:retry`. */
    public function retryFailedJob(string $uuid): void
    {
        \Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$uuid]]);
    }

    public function deleteFailedJob(string $uuid): bool
    {
        return DB::table('failed_jobs')->where('uuid', $uuid)->delete() > 0;
    }

    public function flushFailedJobs(): int
    {
        return DB::table('failed_jobs')->delete();
    }

    /** Truncates a log file in place (rather than deleting it) so an open file handle stays valid. */
    public function clearSystemLog(string $file): bool
    {
        $safe = basename($file);
        $path = storage_path("logs/{$safe}");

        if (!str_ends_with($safe, '.log') || !is_file($path)) {
            return false;
        }

        file_put_contents($path, '');
        return true;
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

    /**
     * Regenerate a company's platform API credentials (app_id + private_token) —
     * the pair external integrations (OTP API, etc.) authenticate with, distinct
     * from per-provider AI keys in CompanyApiKey. The old pair stops working the
     * moment this runs, so the caller must surface the new values immediately —
     * private_token is encrypted at rest and never re-readable in the clear.
     */
    public function resetApiKey(Company $company): array
    {
        $appId = 'WA_APP_'.strtoupper(Str::random(12));
        $raw   = Str::random(40);

        $company->update([
            'app_id'        => $appId,
            'private_token' => encrypt($raw),
        ]);

        return ['app_id' => $appId, 'private_token' => $raw];
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
