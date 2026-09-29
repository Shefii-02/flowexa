<?php

namespace App\Modules\WaChat\Services\Agent;

use App\Models\Company;
use App\Services\CompanyApiKeyResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Speech in and out for the AI agent, shared by the real inbound webhook path (both channels)
 * and the dashboard's Live Test Chat: Whisper transcribes an inbound voice note into text so
 * understanding a voice note works the same as a typed one, and OpenAI TTS turns the agent's
 * text answer into a voice-note reply when the inbound message that triggered it was itself
 * voice. Both directions use the company's own OpenAI key (CompanyApiKeyResolver::openai) —
 * the one already required for Whisper in the test tool, so this adds no new provider/config
 * surface for a company that already has voice working there.
 */
class VoiceService
{
    /**
     * @param string $bytes raw audio bytes (whatever format the source sent — webm/ogg/opus/mp3…)
     * @param ?string $overrideKey test-tool only: a key typed into the tester should also drive
     *        transcription, not just the text answer — see AiAgentController::voiceTest.
     */
    public function transcribe(string $bytes, string $filename, Company $company, ?string $overrideKey = null): ?string
    {
        $apiKey = $overrideKey ?: CompanyApiKeyResolver::openai($company);
        if (empty($apiKey) || $bytes === '') {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->attach('file', $bytes, $filename)
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model'           => 'whisper-1',
                    'response_format' => 'json',
                ]);

            if ($response->successful()) {
                if (!$overrideKey && $company->openai_key_id && $company->openaiKey) {
                    CompanyApiKeyResolver::recordUsage($company->openaiKey, 0.0);
                }
                return $response->json('text');
            }

            Log::warning('VoiceService: Whisper transcription failed — ' . $response->body());
        } catch (\Throwable $e) {
            Log::error('VoiceService: Whisper transcription exception: ' . $e->getMessage());
        }

        return null;
    }

    /** @return ?string raw MP3 bytes, or null if the company has no OpenAI key or the call failed. */
    public function synthesize(string $text, Company $company): ?string
    {
        $text = trim($text);
        $apiKey = CompanyApiKeyResolver::openai($company);
        if (empty($apiKey) || $text === '') {
            return null;
        }

        try {
            // A voice note doesn't need to read out every character of a long answer — cap it so
            // TTS cost/latency stay bounded; the full text still went out as the message's own
            // caption-equivalent nowhere, so trimming here only shortens what gets spoken.
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model'           => 'tts-1',
                    'voice'           => 'alloy',
                    'input'           => Str::limit($text, 3000, ''),
                    'response_format' => 'mp3',
                ]);

            if ($response->successful()) {
                if ($company->openai_key_id && $company->openaiKey) {
                    CompanyApiKeyResolver::recordUsage($company->openaiKey, 0.0);
                }
                return $response->body();
            }

            Log::warning('VoiceService: TTS synthesis failed — ' . $response->body());
        } catch (\Throwable $e) {
            Log::error('VoiceService: TTS synthesis exception: ' . $e->getMessage());
        }

        return null;
    }

    /** Writes synthesized audio to public storage and returns a fetchable URL for it. */
    public function storePublicly(string $bytes, int $companyId, string $ext = 'mp3'): string
    {
        $path = "voice-replies/{$companyId}/" . Str::uuid() . ".{$ext}";
        Storage::disk('public')->put($path, $bytes);
        return Storage::disk('public')->url($path);
    }
}
