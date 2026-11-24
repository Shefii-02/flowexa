<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** GET /company-package — the "Active package" panel on the dashboard: plan, users, dates, storage, upload limit, WhatsApp access. */
class CompanyPackageController extends Controller
{
    private const MAX_UPLOAD_MB = 100;   // MediaLibraryController::MAX_FILE_MB

    public function show(): JsonResponse
    {
        $u = auth()->user();
        $company = $u->company()->with('plan')->first();
        abort_unless($company, 404);
        $plan = $company->plan;
        $cid = (int) $company->id;
        $has = fn (string $t) => Schema::hasTable($t);

        $storage = \App\Support\MediaStorage::summary($company);

        return response()->json([
            'plan' => $plan?->name,
            'users' => ['used' => DB::table('users')->where('company_id', $cid)->where('is_active', true)->count(), 'limit' => $plan?->max_users],
            'starts_on' => $company->created_at?->toDateString(),
            'ends_on' => $company->plan_expires_at?->toDateString(),
            'days_left' => $company->plan_expires_at ? (int) now()->diffInDays($company->plan_expires_at, false) : null,
            'storage' => ['used_mb' => $storage['used_mb'], 'limit_mb' => $storage['limit_mb'], 'percent' => $storage['percent']],
            'max_upload_mb' => self::MAX_UPLOAD_MB,
            'whatsapp' => [
                'chat_sessions' => $has('openwa_sessions') ? DB::table('openwa_sessions')->where('company_id', $cid)->count() : 0,
                'cloud_numbers' => $has('wa_phone_numbers') ? DB::table('wa_phone_numbers')->where('company_id', $cid)->count() : 0,
            ],
        ]);
    }
}
