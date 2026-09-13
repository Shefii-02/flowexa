<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google renames/retires pinned Gemini model ids over time (e.g. gemini-1.5-flash
 * stopped supporting generateContent) — a hardcoded id list goes stale silently,
 * and the only symptom is a cryptic "model not found for generateContent" the next
 * time someone actually sends a message. This asks Google's own ModelService
 * directly what a given key can currently call, so the app self-heals instead of
 * carrying a guess that can go wrong again later.
 */
class GoogleAiModelService
{
    /**
     * Fallback only — used when ListModels itself can't be reached/parsed. Confirmed real
     * via a live test call + Google's own docs during this integration (2026-09), not a
     * guess: gemini-3.6-flash (production default), gemini-3.8-flash (complex agents),
     * gemini-3.5-flash-lite (high-throughput classification). Kept short and only used as
     * a last resort — liveModels() below is the source of truth whenever it succeeds.
     *
     * Per explicit product decision, only the 3.5–3.8 generation is offered at all — see
     * MODEL_ALLOWLIST_PATTERN below, which enforces the same restriction on live-fetched
     * models too, not just this fallback list.
     */
    private const FALLBACK_MODELS = [
        ['id' => 'gemini-3.6-flash',     'label' => 'Gemini 3.6 Flash',      'speed' => 'fast', 'cost' => '$',  'description' => 'Recommended — production workhorse'],
        ['id' => 'gemini-3.7-flash',     'label' => 'Gemini 3.7 Flash',      'speed' => 'fast', 'cost' => '$',  'description' => 'Balanced speed and quality'],
        ['id' => 'gemini-3.8-flash',     'label' => 'Gemini 3.8 Flash',      'speed' => 'fast', 'cost' => '$$', 'description' => 'Complex agents / long-horizon tasks'],
        ['id' => 'gemini-3.5-flash-lite','label' => 'Gemini 3.5 Flash Lite', 'speed' => 'fast', 'cost' => '$',  'description' => 'High-throughput classification'],
    ];

    /** Keep in sync with CompanyApiKeyResolver::GOOGLE_AI_MODEL_PATTERN. */
    private const MODEL_ALLOWLIST_PATTERN = '/^gemini-3\.[5-8](-|$)/';

    /** @return array<int, array{id:string,label:string,speed:string,cost:string,description:string}>|null */
    public static function liveModels(string $apiKey): ?array
    {
        return Cache::remember('google_ai_models:' . md5($apiKey), now()->addHours(6), function () use ($apiKey) {
            try {
                // Header auth (x-goog-api-key), not ?key= — the ?key= query param is what the
                // now-defunct generateContent call used; the confirmed-working Interactions
                // API call uses the header, so ListModels is kept consistent with it.
                $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                    ->timeout(10)->get('https://generativelanguage.googleapis.com/v1beta/models');
                if (!$response->successful()) return self::FALLBACK_MODELS;

                $raw = collect($response->json('models', []));
                if ($raw->isEmpty()) return self::FALLBACK_MODELS;

                $usable = $raw->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? []));

                // The Interactions API is a newer surface than ListModels' own vocabulary —
                // "generateContent" support may no longer be how it flags a text-capable
                // model. If strict filtering finds nothing despite real models coming back,
                // fall back to excluding only obvious non-text models by name instead of
                // trusting a method string that might not describe the new API at all.
                if ($usable->isEmpty()) {
                    $usable = $raw->reject(fn ($m) => str_contains($m['name'] ?? '', 'embedding') || str_contains($m['name'] ?? '', 'aqa'));
                }

                // Per explicit product decision, only the 3.5–3.8 generation is offered —
                // even a live, working model outside that range (an older 1.x/2.x id Google
                // hasn't fully retired yet, or a future 3.9/4.x) is filtered out here rather
                // than surfaced as a pickable option.
                $usable = $usable->filter(fn ($m) => preg_match(self::MODEL_ALLOWLIST_PATTERN, str_replace('models/', '', $m['name'] ?? '')));
                if ($usable->isEmpty()) {
                    return self::FALLBACK_MODELS;
                }

                $models = $usable
                    ->map(fn ($m) => [
                        'id'          => str_replace('models/', '', $m['name']),
                        'label'       => $m['displayName'] ?? str_replace('models/', '', $m['name']),
                        'speed'       => str_contains($m['name'], 'flash') ? 'fast' : 'medium',
                        'cost'        => str_contains($m['name'], 'flash') ? '$' : '$$',
                        'description' => $m['description'] ?? '',
                    ])
                    ->values()
                    ->all();

                return $models ?: self::FALLBACK_MODELS;
            } catch (\Throwable $e) {
                Log::warning('GoogleAiModelService::liveModels failed: ' . $e->getMessage());
                return self::FALLBACK_MODELS;
            }
        });
    }

    /** Best default model id for a freshly-activated key: prefer a "flash" model, else the first available. */
    public static function pickDefaultModel(string $apiKey): ?string
    {
        $models = self::liveModels($apiKey);
        if (!$models) return null;

        $flash = collect($models)->first(fn ($m) => str_contains($m['id'], 'flash'));
        return $flash['id'] ?? $models[0]['id'];
    }

    /**
     * Calls the Interactions API (confirmed working shape: POST /v1beta/interactions with
     * an `x-goog-api-key` header and a {model, input} body — NOT the older
     * /v1beta/models/{model}:generateContent + ?key= shape, which Google has moved
     * models like gemini-3.x off of). $input is one combined string — system prompt and
     * conversation history folded in as plain text — since a structured multi-turn
     * `input` array for this endpoint isn't confirmed, and a single string is guaranteed
     * to actually carry the grounding context through rather than risking it being
     * silently dropped by an unconfirmed field name.
     *
     * @param array{status?:int,detail?:string} $debug Optional out-param populated on failure.
     */
    public static function generate(string $apiKey, string $model, string $input, array &$debug = []): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type'   => 'application/json',
            ])->timeout(30)->post('https://generativelanguage.googleapis.com/v1beta/interactions', [
                'model' => $model,
                'input' => $input,
            ]);

            if (!$response->successful()) {
                Log::warning('GoogleAiModelService::generate error: ' . $response->body());
                $debug = ['status' => $response->status(), 'detail' => $response->json('error.message') ?? mb_substr($response->body(), 0, 300)];
                return null;
            }

            $json = $response->json();
            $text = self::extractText($json);
            if ($text !== null) return $text;

            Log::warning('GoogleAiModelService::generate returned no text: ' . $response->body());

            // The Interactions API's failure shape for things like safety blocks isn't
            // confirmed yet (unlike the old generateContent endpoint's documented
            // promptFeedback/finishReason fields) — rather than guess field names, surface
            // whatever the response itself carries (status, any reason/error-like field)
            // so a real block still shows *something* actionable instead of a bare "no text".
            $apiStatus = $json['status'] ?? 'unknown';
            $reason    = $json['reason'] ?? $json['error'] ?? $json['status_reason'] ?? null;
            $detail    = "No usable output (status: {$apiStatus})" . ($reason ? ' — ' . (is_string($reason) ? $reason : json_encode($reason)) : '');

            $debug = ['status' => 200, 'detail' => $detail];
            return null;
        } catch (\Throwable $e) {
            Log::error('GoogleAiModelService::generate exception: ' . $e->getMessage());
            $debug = ['detail' => $e->getMessage()];
            return null;
        }
    }

    /**
     * Extracts the final model_output text from an Interactions API response. A response
     * carries a `steps` array mixing internal 'thought' steps (no visible content) with
     * 'model_output' steps (the actual reply, as a `content` array of {type:"text", text}).
     */
    public static function extractText(?array $json): ?string
    {
        if (!$json || ($json['status'] ?? null) !== 'completed') return null;

        foreach ($json['steps'] ?? [] as $step) {
            if (($step['type'] ?? null) === 'model_output') {
                $text = collect($step['content'] ?? [])
                    ->where('type', 'text')
                    ->pluck('text')
                    ->filter()
                    ->implode("\n");
                if ($text !== '') return $text;
            }
        }

        return null;
    }
}
