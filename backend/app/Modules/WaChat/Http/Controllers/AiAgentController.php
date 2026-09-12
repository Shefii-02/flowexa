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
            'use_rag'        => 'nullable|boolean',
        ]);

        $companyId = Auth::user()->company_id;

        $result = $this->rag->answer(
            // request->query is Symfony's own $query property (the URL query-string bag), NOT
            // Laravel's dynamic input accessor — it silently shadows the 'query' field entirely
            // and previously threw a TypeError on every single call (an InputBag where the
            // strictly-typed `string $query` parameter expects a string). ->input('query')
            // reads the validated field the same way contact_phone/session_id already do.
            query:         $request->input('query'),
            contactPhone:  $request->contact_phone,
            wahaSessionId: $request->session_id,
            companyId:     $companyId,
            aiConfig:      $request->input('ai_config', []),
            // /wa-agent/ask has no real caller besides this dashboard's own test chat (real
            // inbound messages go through ConversationalAgentService/RagOrchestrator directly) —
            // always test-mode, so repeated testing never creates a fake Contact/Lead/session.
            isTest:        true,
            useRag:        $request->boolean('use_rag', true),
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
            'use_rag'       => 'nullable|boolean',
            'ai_config'     => 'nullable|string', // JSON-encoded (multipart form can't nest arrays)
        ]);

        $company = Company::find(Auth::user()->company_id) ?? new Company();
        $forcedConfig = json_decode((string) $request->input('ai_config', '{}'), true) ?: [];

        // A forced OpenAI key in the test tool should also drive Whisper transcription —
        // otherwise "force provider" only affects the text answer and transcription silently
        // falls back to the company's own saved key (or fails if it doesn't have one),
        // even though the tester explicitly supplied a key to test with.
        $whisperKey = ($forcedConfig['provider'] ?? null) === 'openai' && !empty($forcedConfig['api_key'])
            ? $forcedConfig['api_key']
            : null;

        $transcript = $this->transcribeWithWhisper($request->file('audio'), $company, $whisperKey);

        if ($transcript === null) {
            return response()->json([
                'error' => 'Voice transcription unavailable. Add an OpenAI key in Settings → API Keys (or pick "openai" under "AI key to test" with a key) to enable Whisper.',
            ], 422);
        }

        $result = $this->rag->answer(
            query:         $transcript,
            contactPhone:  $request->contact_phone,
            wahaSessionId: $request->session_id,
            companyId:     $company->id,
            aiConfig:      array_merge(['response_mode' => $request->input('response_mode', 'text')], $forcedConfig),
            isTest:        true,
            useRag:        $request->boolean('use_rag', true),
        );

        return response()->json(array_merge($result, ['transcript' => $transcript]));
    }

    private function transcribeWithWhisper(UploadedFile $audio, Company $company, ?string $overrideKey = null): ?string
    {
        $apiKey = $overrideKey ?: CompanyApiKeyResolver::openai($company);
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
                // Record usage on the company's OpenAI key — only meaningful when we
                // actually used it, not a one-off key typed into the test tool.
                if (!$overrideKey && $company->openai_key_id && $company->openaiKey) {
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

    /** Static catalogue of selectable models per provider. */
    public static function modelCatalogue(): array
    {
        return [
            'anthropic' => [
                ['id' => 'claude-haiku-4-5-20251001',  'label' => 'Claude Haiku 4.5',  'speed' => 'fast',   'cost' => '$',    'description' => 'Best for high volume'],
                ['id' => 'claude-sonnet-4-6-20251001', 'label' => 'Claude Sonnet 4.6', 'speed' => 'medium', 'cost' => '$$',   'description' => 'Better quality'],
                ['id' => 'claude-opus-4-8-20251001',   'label' => 'Claude Opus 4.8',   'speed' => 'slow',   'cost' => '$$$$', 'description' => 'Best quality'],
            ],
            'openai' => [
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'speed' => 'fast',   'cost' => '$',  'description' => 'Fast and cheap'],
                ['id' => 'gpt-4o',      'label' => 'GPT-4o',      'speed' => 'medium', 'cost' => '$$', 'description' => 'Best OpenAI model'],
            ],
            'google_ai' => [
                ['id' => 'gemini-1.5-flash', 'label' => 'Gemini 1.5 Flash', 'speed' => 'fast',   'cost' => '$',  'description' => 'Free tier available'],
                ['id' => 'gemini-1.5-pro',   'label' => 'Gemini 1.5 Pro',   'speed' => 'medium', 'cost' => '$$', 'description' => 'Best Gemini model'],
            ],
        ];
    }

    // ── Available models (for UI model selector) ───────────────────────────────

    public function availableModels(): JsonResponse
    {
        $company   = Company::find(Auth::user()->company_id) ?? new Company();
        $catalogue = self::modelCatalogue();

        return response()->json(collect($catalogue)->map(fn($models, $provider) => [
            'provider'        => $provider,
            'has_key'         => !empty(CompanyApiKeyResolver::keyForProvider($company, $provider)),
            'active_key_hint' => CompanyApiKeyResolver::activeKeyModel($company, $provider)?->api_key_hint,
            'models'          => $models,
        ])->values());
    }

    // ── Consolidated AI settings (wa-agent → Settings tab) ────────────────────

    public function aiSettings(): JsonResponse
    {
        $company = Company::find(Auth::user()->company_id) ?? new Company();
        return response()->json(self::buildAiSettings($company));
    }

    /** Shared by aiSettings() (self, via the JWT) and SuperAdminController (any company, by id). */
    public static function buildAiSettings(Company $company): array
    {
        $platform  = CompanyApiKeyResolver::platformCompany();
        $isPlatform = $platform && $platform->id === $company->id;
        $catalogue = self::modelCatalogue();

        $providers = collect($catalogue)->map(function ($models, $provider) use ($company, $platform, $isPlatform) {
            $companyKey  = CompanyApiKeyResolver::activeKeyModel($company, $provider);
            $platformKey = (!$isPlatform && $platform)
                ? CompanyApiKeyResolver::activeKeyModel($platform, $provider)
                : null;

            $source = $companyKey ? 'company' : ($platformKey ? 'platform' : 'none');

            return [
                'provider'          => $provider,
                'models'            => $models,
                'source'            => $source,
                'has_company_key'   => (bool) $companyKey,
                'has_platform_key'  => (bool) $platformKey,
                'active_key_hint'   => $companyKey?->api_key_hint ?? $platformKey?->api_key_hint,
            ];
        })->values();

        $resolved = CompanyApiKeyResolver::resolve($company);

        return [
            'active_provider'   => CompanyApiKeyResolver::provider($company),
            'active_model'      => CompanyApiKeyResolver::model($company),
            'resolved_source'   => $resolved['source'] ?? 'none',
            'is_platform_admin' => $isPlatform,
            'providers'         => $providers,
        ];
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
