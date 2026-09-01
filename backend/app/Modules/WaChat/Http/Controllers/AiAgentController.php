<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\WaChat\Models\AiAgentSession;
use App\Modules\WaChat\Models\AutomationLog;
use App\Modules\WaChat\Services\Rag\RagOrchestrator;
use App\Services\CompanyApiKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAgentController extends Controller
{
    public function __construct(private readonly RagOrchestrator $rag) {}

    // ── Ask (text query → RAG response) ───────────────────────────────────────

    public function ask(Request $request): JsonResponse
    {
        $request->validate([
            'query'          => 'required|string|max:2000',
            'contact_phone'  => 'required|string',
            'session_id'     => 'required|string',
            'ai_config'      => 'nullable|array',
        ]);

        $companyId = Auth::user()->company_id;

        $result = $this->rag->answer(
            query:         $request->query,
            contactPhone:  $request->contact_phone,
            wahaSessionId: $request->session_id,
            companyId:     $companyId,
            aiConfig:      $request->input('ai_config', []),
        );

        return response()->json($result);
    }

    // ── Voice test (audio → Whisper STT → RAG response) ───────────────────────

    public function voiceTest(Request $request): JsonResponse
    {
        $request->validate([
            'audio'         => 'required|file|max:25600',
            'contact_phone' => 'required|string',
            'session_id'    => 'required|string',
            'response_mode' => 'nullable|string|in:text,voice,document,video',
        ]);

        $company = Company::find(Auth::user()->company_id) ?? new Company();

        $transcript = $this->transcribeWithWhisper($request->file('audio'), $company);

        if ($transcript === null) {
            return response()->json([
                'error' => 'Voice transcription unavailable. Add an OpenAI key in Settings → API Keys to enable Whisper.',
            ], 422);
        }

        $result = $this->rag->answer(
            query:         $transcript,
            contactPhone:  $request->contact_phone,
            wahaSessionId: $request->session_id,
            companyId:     $company->id,
            aiConfig:      ['response_mode' => $request->input('response_mode', 'text')],
        );

        return response()->json(array_merge($result, ['transcript' => $transcript]));
    }

    private function transcribeWithWhisper(UploadedFile $audio, Company $company): ?string
    {
        $apiKey = CompanyApiKeyResolver::openai($company);
        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->attach('file', $audio->get(), $audio->getClientOriginalName() ?: 'voice.webm')
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model'           => 'whisper-1',
                    'response_format' => 'json',
                ]);

            if ($response->successful()) {
                // Record usage on the company's OpenAI key
                if ($company->openai_key_id && $company->openaiKey) {
                    CompanyApiKeyResolver::recordUsage($company->openaiKey, 0.0);
                }
                return $response->json('text');
            }

            Log::warning('Whisper API error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Whisper transcription exception: ' . $e->getMessage());
        }

        return null;
    }

    // ── Available models (for UI model selector) ───────────────────────────────

    public function availableModels(): JsonResponse
    {
        $company = Company::find(Auth::user()->company_id) ?? new Company();

        $anthropicHint = $company->anthropicKey?->api_key_hint;
        $openaiHint    = $company->openaiKey?->api_key_hint;

        return response()->json([
            [
                'provider'        => 'anthropic',
                'has_key'         => !empty(CompanyApiKeyResolver::anthropic($company)),
                'active_key_hint' => $anthropicHint,
                'models'          => [
                    ['id' => 'claude-haiku-4-5-20251001',  'label' => 'Claude Haiku 4.5',  'speed' => 'fast',   'cost' => '$',    'description' => 'Best for high volume'],
                    ['id' => 'claude-sonnet-4-6-20251001', 'label' => 'Claude Sonnet 4.6', 'speed' => 'medium', 'cost' => '$$',   'description' => 'Better quality'],
                    ['id' => 'claude-opus-4-8-20251001',   'label' => 'Claude Opus 4.8',   'speed' => 'slow',   'cost' => '$$$$', 'description' => 'Best quality'],
                ],
            ],
            [
                'provider'        => 'openai',
                'has_key'         => !empty(CompanyApiKeyResolver::openai($company)),
                'active_key_hint' => $openaiHint,
                'models'          => [
                    ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'speed' => 'fast',   'cost' => '$',  'description' => 'Fast and cheap'],
                    ['id' => 'gpt-4o',      'label' => 'GPT-4o',      'speed' => 'medium', 'cost' => '$$', 'description' => 'Best OpenAI model'],
                ],
            ],
            [
                'provider'        => 'google_ai',
                'has_key'         => false,
                'active_key_hint' => null,
                'models'          => [
                    ['id' => 'gemini-1.5-flash', 'label' => 'Gemini 1.5 Flash', 'speed' => 'fast',   'cost' => '$',  'description' => 'Free tier available'],
                    ['id' => 'gemini-1.5-pro',   'label' => 'Gemini 1.5 Pro',   'speed' => 'medium', 'cost' => '$$', 'description' => 'Best Gemini model'],
                ],
            ],
        ]);
    }

    // ── Save AI agent config (provider/model) ──────────────────────────────────

    public function saveConfig(Request $request): JsonResponse
    {
        $request->validate([
            'ai_provider'      => 'nullable|string|in:anthropic,openai,google_ai',
            'ai_model'         => 'nullable|string|max:100',
            'openai_key_id'    => 'nullable|integer',
            'anthropic_key_id' => 'nullable|integer',
        ]);

        $company = Company::findOrFail(Auth::user()->company_id);
        $company->update(array_filter([
            'ai_provider'      => $request->ai_provider,
            'ai_model'         => $request->ai_model,
            'openai_key_id'    => $request->openai_key_id,
            'anthropic_key_id' => $request->anthropic_key_id,
        ], fn($v) => $v !== null));

        return response()->json(['message' => 'AI config saved.']);
    }

    // ── Session management ─────────────────────────────────────────────────────

    public function sessions(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $sessions = AiAgentSession::where('company_id', $companyId)
            ->when($request->status,     fn($q) => $q->where('status', $request->status))
            ->when($request->session_id, fn($q) => $q->where('waha_session_id', $request->session_id))
            ->orderBy('last_message_at', 'desc')
            ->paginate(30);

        return response()->json($sessions);
    }

    public function sessionDetail(int $id): JsonResponse
    {
        $session = AiAgentSession::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        return response()->json($session);
    }

    public function closeSession(int $id): JsonResponse
    {
        $session = AiAgentSession::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $session->update(['status' => 'closed']);
        return response()->json(['message' => 'Session closed.']);
    }

    public function transferSession(int $id): JsonResponse
    {
        $session = AiAgentSession::where('id', $id)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $session->update(['status' => 'transferred']);
        return response()->json(['message' => 'Session transferred to human agent.']);
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $since     = now()->subDays($request->input('days', 7));

        $total       = AiAgentSession::where('company_id', $companyId)->count();
        $active      = AiAgentSession::where('company_id', $companyId)->where('status', 'active')->count();
        $transferred = AiAgentSession::where('company_id', $companyId)->where('status', 'transferred')->count();

        $automationLogs = AutomationLog::where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->selectRaw('rule_type, status, COUNT(*) as count')
            ->groupBy('rule_type', 'status')
            ->get();

        return response()->json([
            'sessions'        => ['total' => $total, 'active' => $active, 'transferred' => $transferred],
            'automation_logs' => $automationLogs,
        ]);
    }
}
