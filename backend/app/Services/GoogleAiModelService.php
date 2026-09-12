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
    /** @return array<int, array{id:string,label:string,speed:string,cost:string,description:string}>|null */
    public static function liveModels(string $apiKey): ?array
    {
        return Cache::remember('google_ai_models:' . md5($apiKey), now()->addHours(6), function () use ($apiKey) {
            try {
                $response = Http::timeout(10)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $apiKey]);
                if (!$response->successful()) return null;

                $models = collect($response->json('models', []))
                    ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? []))
                    ->map(fn ($m) => [
                        'id'          => str_replace('models/', '', $m['name']),
                        'label'       => $m['displayName'] ?? str_replace('models/', '', $m['name']),
                        'speed'       => str_contains($m['name'], 'flash') ? 'fast' : 'medium',
                        'cost'        => str_contains($m['name'], 'flash') ? '$' : '$$',
                        'description' => $m['description'] ?? '',
                    ])
                    ->values()
                    ->all();

                return $models ?: null;
            } catch (\Throwable $e) {
                Log::warning('GoogleAiModelService::liveModels failed: ' . $e->getMessage());
                return null;
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
}
