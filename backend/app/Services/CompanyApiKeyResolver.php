<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyApiKey;
use Illuminate\Support\Facades\DB;

/**
 * Central resolver for per-company AI API keys.
 * Priority: company DB key → .env fallback → null
 *
 * NEVER return decrypted keys to the frontend.
 * Call these methods only inside server-side AI service code.
 */
class CompanyApiKeyResolver
{
    // ── Key resolution ─────────────────────────────────────────────────────────

    public static function openai(Company $company): ?string
    {
        if ($company->openai_key_id) {
            $key = CompanyApiKey::find($company->openai_key_id);
            if ($key && $key->is_active && !$key->isAtLimit()) {
                return ApiKeyEncryption::decrypt($key->api_key);
            }
        }

        $envKey = config('services.openai.key', env('OPENAI_API_KEY', ''));
        return empty($envKey) ? null : $envKey;
    }

    public static function anthropic(Company $company): ?string
    {
        if ($company->anthropic_key_id) {
            $key = CompanyApiKey::find($company->anthropic_key_id);
            if ($key && $key->is_active && !$key->isAtLimit()) {
                return ApiKeyEncryption::decrypt($key->api_key);
            }
        }

        $envKey = config('services.anthropic.api_key', env('ANTHROPIC_API_KEY', ''));
        return empty($envKey) ? null : $envKey;
    }

    public static function model(Company $company): string
    {
        return $company->ai_model
            ?? config('services.anthropic.model', 'claude-haiku-4-5');
    }

    public static function provider(Company $company): string
    {
        return $company->ai_provider ?? 'anthropic';
    }

    // ── Usage tracking ─────────────────────────────────────────────────────────

    public static function recordUsage(CompanyApiKey $key, float $costUsd = 0.0): void
    {
        $key->increment('usage_count');
        $key->update([
            'last_used_at'     => now(),
            'monthly_used_usd' => DB::raw("monthly_used_usd + {$costUsd}"),
        ]);
    }

    public static function checkLimit(CompanyApiKey $key): bool
    {
        if (!$key->monthly_limit_usd) return true;
        return $key->monthly_used_usd < $key->monthly_limit_usd;
    }

    // ── Monthly reset (called by cron on 1st of each month) ────────────────────

    public static function resetMonthlyUsage(): void
    {
        CompanyApiKey::query()->update(['monthly_used_usd' => 0]);
    }

    // ── Load the active key model (for UI) ─────────────────────────────────────

    public static function openaiKeyModel(Company $company): ?CompanyApiKey
    {
        return $company->openai_key_id
            ? CompanyApiKey::find($company->openai_key_id)
            : null;
    }

    public static function anthropicKeyModel(Company $company): ?CompanyApiKey
    {
        return $company->anthropic_key_id
            ? CompanyApiKey::find($company->anthropic_key_id)
            : null;
    }
}
