<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies AI provider API keys before saving them to the database.
 * Makes a minimal real API call to confirm the key is accepted.
 */
class ApiKeyVerifier
{
    public static function verify(string $provider, string $apiKey): array
    {
        return match ($provider) {
            'openai'    => self::verifyOpenAI($apiKey),
            'anthropic' => self::verifyAnthropic($apiKey),
            'google_ai' => self::verifyGoogleAI($apiKey),
            default     => ['valid' => false, 'message' => 'Unknown provider'],
        };
    }

    // ── OpenAI ─────────────────────────────────────────────────────────────────

    public static function verifyOpenAI(string $apiKey): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->get('https://api.openai.com/v1/models');

            return match ($response->status()) {
                200     => ['valid' => true,  'message' => 'OpenAI key is valid'],
                401     => ['valid' => false, 'message' => 'Invalid API key'],
                429     => ['valid' => true,  'message' => 'Key valid but rate limited'],
                default => ['valid' => false, 'message' => "Unexpected status: {$response->status()}"],
            };
        } catch (\Exception $e) {
            Log::warning('ApiKeyVerifier::verifyOpenAI: ' . $e->getMessage());
            return ['valid' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    // ── Anthropic ─────────────────────────────────────────────────────────────

    public static function verifyAnthropic(string $apiKey): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(15)->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 10,
                'messages'   => [['role' => 'user', 'content' => 'Hi']],
            ]);

            return match ($response->status()) {
                200     => ['valid' => true,  'message' => 'Anthropic key is valid', 'model' => 'claude-haiku-4-5'],
                401     => ['valid' => false, 'message' => 'Invalid API key'],
                529     => ['valid' => true,  'message' => 'Key valid but Anthropic is overloaded'],
                default => ['valid' => false, 'message' => "Unexpected status: {$response->status()}"],
            };
        } catch (\Exception $e) {
            Log::warning('ApiKeyVerifier::verifyAnthropic: ' . $e->getMessage());
            return ['valid' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    // ── Google AI ─────────────────────────────────────────────────────────────

    public static function verifyGoogleAI(string $apiKey): array
    {
        try {
            // Header auth (x-goog-api-key), not ?key= — the old query-param form matched
            // the now-defunct generateContent endpoint. ListModels only proves the key is
            // accepted, not that a call can actually generate a response — this is exactly
            // how a key ended up "Verified" while every real message still failed with
            // "model not found for generateContent" — so a real Interactions API call is
            // made against the key's own best available model instead of just listing.
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(10)
                ->get('https://generativelanguage.googleapis.com/v1beta/models');

            if (in_array($response->status(), [400, 401, 403], true)) {
                return ['valid' => false, 'message' => 'Invalid API key'];
            }
            if (!$response->successful()) {
                return ['valid' => false, 'message' => "Unexpected status: {$response->status()}"];
            }

            $model = GoogleAiModelService::pickDefaultModel($apiKey);
            if (!$model) {
                return ['valid' => false, 'message' => 'Key accepted, but no usable model was found for it'];
            }

            $debug = [];
            $text  = GoogleAiModelService::generate($apiKey, $model, 'Reply with the single word: OK', $debug);

            if ($text !== null) {
                return ['valid' => true, 'message' => 'Google AI key is valid', 'model' => $model];
            }

            return ['valid' => false, 'message' => 'Key accepted but a test generation failed: ' . ($debug['detail'] ?? 'unknown error')];
        } catch (\Exception $e) {
            Log::warning('ApiKeyVerifier::verifyGoogleAI: ' . $e->getMessage());
            return ['valid' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }
}
