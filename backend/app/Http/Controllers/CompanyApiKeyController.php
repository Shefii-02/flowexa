<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Services\ApiKeyEncryption;
use App\Services\ApiKeyVerifier;
use App\Services\CompanyApiKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyApiKeyController extends Controller
{
    private function company(): Company
    {
        return Company::findOrFail(Auth::user()->company_id);
    }

    // ── List keys (hints only, never decrypted) ────────────────────────────────

    public function index(): JsonResponse
    {
        $keys = CompanyApiKey::where('company_id', Auth::user()->company_id)
            ->orderBy('provider')
            ->orderBy('key_label')
            ->get()
            ->makeHidden(['api_key']); // double-guard; model already hides it

        return response()->json($keys);
    }

    // ── Test key without saving ────────────────────────────────────────────────

    public function testKey(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => 'required|string|in:openai,anthropic,google_ai,custom',
            'api_key'  => 'required|string|min:10',
        ]);

        $result = ApiKeyVerifier::verify($request->provider, $request->api_key);

        return response()->json($result, $result['valid'] ? 200 : 422);
    }

    // ── Store (verify → encrypt → save hint) ──────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'provider'           => 'required|string|in:openai,anthropic,google_ai,custom',
            'key_label'          => 'required|string|max:100',
            'api_key'            => 'required|string|min:10',
            'monthly_limit_usd'  => 'nullable|numeric|min:0',
            'set_as_active'      => 'boolean',
        ]);

        $company = $this->company();

        // Verify before saving
        $verification = ApiKeyVerifier::verify($request->provider, $request->api_key);
        if (!$verification['valid']) {
            return response()->json([
                'message' => 'API key verification failed: ' . $verification['message'],
                'valid'   => false,
            ], 422);
        }

        $encrypted = ApiKeyEncryption::encrypt($request->api_key);
        $hint      = ApiKeyEncryption::hint($request->api_key);

        $key = CompanyApiKey::create([
            'company_id'        => $company->id,
            'provider'          => $request->provider,
            'key_label'         => $request->key_label,
            'api_key'           => $encrypted,
            'api_key_hint'      => $hint,
            'is_active'         => false,
            'is_verified'       => true,
            'last_verified_at'  => now(),
            'monthly_limit_usd' => $request->monthly_limit_usd,
            'created_by'        => Auth::id(),
        ]);

        if ($request->boolean('set_as_active')) {
            $this->activateKey($key, $company);
        }

        return response()->json($key->makeHidden(['api_key']), 201);
    }

    // ── Update label / limit / active flag only (never the raw key) ───────────

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'key_label'         => 'sometimes|string|max:100',
            'monthly_limit_usd' => 'nullable|numeric|min:0',
            'is_active'         => 'sometimes|boolean',
        ]);

        $key = CompanyApiKey::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($request->has('key_label'))         $key->key_label         = $request->key_label;
        if ($request->has('monthly_limit_usd')) $key->monthly_limit_usd = $request->monthly_limit_usd;

        if ($request->has('is_active') && $request->boolean('is_active')) {
            $this->activateKey($key, $this->company());
        } elseif ($request->has('is_active') && !$request->boolean('is_active')) {
            $key->is_active = false;
        }

        $key->save();

        return response()->json($key->makeHidden(['api_key']));
    }

    // ── Delete (block if it is the active key) ─────────────────────────────────

    public function destroy(int $id): JsonResponse
    {
        $key     = CompanyApiKey::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $company = $this->company();

        $isActiveOpenai    = $company->openai_key_id    === $key->id;
        $isActiveAnthropic = $company->anthropic_key_id === $key->id;

        if ($isActiveOpenai || $isActiveAnthropic) {
            return response()->json([
                'message' => 'Cannot delete the active key. Set another key as active first.',
            ], 409);
        }

        $key->delete();

        return response()->json(['message' => 'Key deleted.']);
    }

    // ── Re-verify an existing key ──────────────────────────────────────────────

    public function verify(int $id): JsonResponse
    {
        $key = CompanyApiKey::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $decrypted = ApiKeyEncryption::decrypt($key->api_key);
        $result    = ApiKeyVerifier::verify($key->provider, $decrypted);

        $key->update([
            'is_verified'      => $result['valid'],
            'last_verified_at' => now(),
        ]);

        return response()->json(array_merge($result, ['key_id' => $key->id]));
    }

    // ── Set key as active for its provider ────────────────────────────────────

    public function setActive(int $id): JsonResponse
    {
        $key     = CompanyApiKey::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $company = $this->company();
        $this->activateKey($key, $company);

        return response()->json(['message' => 'Key set as active.']);
    }

    // ── Internal: mark key active and deactivate others of same provider ───────

    private function activateKey(CompanyApiKey $key, Company $company): void
    {
        // Deactivate all other keys for this provider
        CompanyApiKey::where('company_id', $company->id)
            ->where('provider', $key->provider)
            ->where('id', '!=', $key->id)
            ->update(['is_active' => false]);

        $key->is_active = true;
        $key->save();

        // Update company pointer
        $field = match ($key->provider) {
            'openai'    => 'openai_key_id',
            'anthropic' => 'anthropic_key_id',
            default     => null,
        };

        if ($field) {
            $company->update([$field => $key->id]);
        }
    }
}
