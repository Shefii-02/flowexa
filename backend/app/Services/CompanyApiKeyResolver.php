<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyApiKey;
use Illuminate\Support\Facades\DB;

/**
 * Central resolver for per-company AI API keys.
 *
 * Priority for any provider:
 *   1. the company's own active + in-limit key for that provider
 *   2. the superadmin "platform" company's active key for that provider
 *      (subscription-billed companies consume the platform key — it does not
 *       need to be unique per company)
 *   3. the matching .env key
 *   4. null
 *
 * NEVER return decrypted keys to the frontend.
 * Call these methods only inside server-side AI service code.
 */
class CompanyApiKeyResolver
{
    private const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

    /**
     * Per-provider fallback model, used only when nobody has ever picked one (no
     * Company.ai_model / platform.ai_model set). Keep in sync with the first entry
     * per provider in AiAgentController::modelCatalogue(). Every prior fallback in
     * this class returned the Anthropic default regardless of provider — so a
     * company running on google_ai or openai with no ai_model set would end up
     * asking Gemini/OpenAI for a Claude model id, the call would fail, and the
     * agent would silently drop to its generic "I couldn't find an answer" reply.
     */
    private const DEFAULT_MODELS = [
        'anthropic' => 'claude-haiku-4-5-20251001',
        'openai'    => 'gpt-4o-mini',
        'google_ai' => 'gemini-1.5-flash',
    ];

    /** Cached platform company lookup (slug = "platform", seeded by SuperAdminSeeder). */
    private static ?Company $platform = null;
    private static bool $platformLoaded = false;

    // ── Key resolution ─────────────────────────────────────────────────────────

    public static function openai(Company $company): ?string
    {
        return self::keyForProvider($company, 'openai');
    }

    public static function anthropic(Company $company): ?string
    {
        return self::keyForProvider($company, 'anthropic');
    }

    public static function google(Company $company): ?string
    {
        return self::keyForProvider($company, 'google_ai');
    }

    /**
     * Resolve the decrypted key for a provider, walking company → platform → env.
     */
    public static function keyForProvider(Company $company, string $provider): ?string
    {
        // 1. Company's own key
        if ($key = self::activeKeyModel($company, $provider)) {
            return ApiKeyEncryption::decrypt($key->api_key);
        }

        // 2. Platform (superadmin) fallback — skip if the company IS the platform
        $platform = self::platformCompany();
        if ($platform && $platform->id !== $company->id) {
            if ($key = self::activeKeyModel($platform, $provider)) {
                return ApiKeyEncryption::decrypt($key->api_key);
            }
        }

        // 3. .env fallback
        return self::envKey($provider);
    }

    /**
     * The single active provider for a company: its own choice when it has a key,
     * otherwise the platform's choice, otherwise its own stored preference.
     */
    public static function provider(Company $company): string
    {
        $own = $company->ai_provider ?: 'anthropic';

        if (self::activeKeyModel($company, $own)) {
            return $own;
        }

        $platform = self::platformCompany();
        if ($platform && $platform->id !== $company->id && $platform->ai_provider) {
            if (self::activeKeyModel($platform, $platform->ai_provider)) {
                return $platform->ai_provider;
            }
        }

        return $own;
    }

    /**
     * `ai_model` is a single free-text column shared across every provider — if a company
     * switches provider (e.g. Anthropic → Gemini) without also repicking a model, the column
     * still holds the old provider's model id ("claude-haiku-…"), which the *new* provider's
     * API will reject outright. This is a same-family sanity check, not full validation.
     */
    private static function modelMatchesProvider(string $model, string $provider): bool
    {
        $prefix = match ($provider) {
            'anthropic' => 'claude',
            'openai'    => 'gpt',
            'google_ai' => 'gemini',
            default     => null,
        };
        return $prefix === null || str_starts_with($model, $prefix);
    }

    public static function model(Company $company): string
    {
        $provider = $company->ai_provider ?: 'anthropic';

        if ($company->ai_model && self::modelMatchesProvider($company->ai_model, $provider)
            && self::activeKeyModel($company, $provider)) {
            return $company->ai_model;
        }

        $platform = self::platformCompany();
        if ($platform && $platform->id !== $company->id && $platform->ai_model) {
            $platformProvider = $platform->ai_provider ?: 'anthropic';
            if (self::modelMatchesProvider($platform->ai_model, $platformProvider)
                && self::activeKeyModel($platform, $platformProvider)) {
                return $platform->ai_model;
            }
        }

        return ($company->ai_model && self::modelMatchesProvider($company->ai_model, $provider))
            ? $company->ai_model
            : (self::DEFAULT_MODELS[$provider] ?? self::DEFAULT_MODEL);
    }

    /**
     * One-stop resolution: the provider, decrypted key, model and where it came
     * from. Returns null when no key is available anywhere.
     *
     * @return array{provider:string, key:string, model:string, source:string, key_model:?CompanyApiKey}|null
     */
    public static function resolve(Company $company): ?array
    {
        $provider = self::provider($company);

        // Company key
        if ($key = self::activeKeyModel($company, $provider)) {
            return [
                'provider'  => $provider,
                'key'       => ApiKeyEncryption::decrypt($key->api_key),
                'model'     => self::model($company),
                'source'    => 'company',
                'key_model' => $key,
            ];
        }

        // Platform key
        $platform = self::platformCompany();
        if ($platform && $platform->id !== $company->id) {
            $pProvider = $platform->ai_provider ?: $provider;
            if ($key = self::activeKeyModel($platform, $pProvider)) {
                return [
                    'provider'  => $pProvider,
                    'key'       => ApiKeyEncryption::decrypt($key->api_key),
                    'model'     => (self::modelMatchesProvider($platform->ai_model ?: '', $pProvider) ? $platform->ai_model : null)
                                    ?: (self::DEFAULT_MODELS[$pProvider] ?? self::DEFAULT_MODEL),
                    'source'    => 'platform',
                    'key_model' => $key,
                ];
            }
        }

        // .env key
        if ($envKey = self::envKey($provider)) {
            return [
                'provider'  => $provider,
                'key'       => $envKey,
                'model'     => (self::modelMatchesProvider($company->ai_model ?: '', $provider) ? $company->ai_model : null)
                                ?: (self::DEFAULT_MODELS[$provider] ?? self::DEFAULT_MODEL),
                'source'    => 'env',
                'key_model' => null,
            ];
        }

        return null;
    }

    // ── Key model lookup ──────────────────────────────────────────────────────

    /** The company's active, verified, in-limit key row for a provider (or null). */
    public static function activeKeyModel(Company $company, ?string $provider): ?CompanyApiKey
    {
        $column = $provider ? Company::providerKeyColumn($provider) : null;
        if (!$column || !$company->{$column}) {
            return null;
        }

        $key = CompanyApiKey::find($company->{$column});
        if ($key && $key->is_active && !$key->isAtLimit()) {
            return $key;
        }

        return null;
    }

    public static function openaiKeyModel(Company $company): ?CompanyApiKey
    {
        return self::activeKeyModel($company, 'openai');
    }

    public static function anthropicKeyModel(Company $company): ?CompanyApiKey
    {
        return self::activeKeyModel($company, 'anthropic');
    }

    public static function googleAiKeyModel(Company $company): ?CompanyApiKey
    {
        return self::activeKeyModel($company, 'google_ai');
    }

    public static function platformCompany(): ?Company
    {
        if (!self::$platformLoaded) {
            self::$platform       = Company::where('slug', 'platform')->first();
            self::$platformLoaded = true;
        }

        return self::$platform;
    }

    private static function envKey(string $provider): ?string
    {
        $value = match ($provider) {
            'openai'    => config('services.openai.key', env('OPENAI_API_KEY', '')),
            'anthropic' => config('services.anthropic.api_key', env('ANTHROPIC_API_KEY', '')),
            'google_ai' => config('services.google_ai.key', env('GOOGLE_AI_API_KEY', env('GOOGLE_AI', ''))),
            default     => '',
        };

        return empty($value) ? null : $value;
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
}
