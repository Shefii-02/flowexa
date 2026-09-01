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
            $response = Http::timeout(10)
                ->get("https://generativelanguage.googleapis.com/v1/models?key={$apiKey}");

            return match (true) {
                $response->status() === 200         => ['valid' => true,  'message' => 'Google AI key is valid'],
                in_array($response->status(), [400, 403]) => ['valid' => false, 'message' => 'Invalid API key'],
                default => ['valid' => false, 'message' => "Unexpected status: {$response->status()}"],
            };
        } catch (\Exception $e) {
            Log::warning('ApiKeyVerifier::verifyGoogleAI: ' . $e->getMessage());
            return ['valid' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }
}
