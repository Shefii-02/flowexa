<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeConversation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ConversationAnalysis;
use App\Models\LeadConversionEvent;
use App\Models\MetaAiConfig;
use App\Services\MetaAI\LeadScoreCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MetaAiController extends Controller
{
    // ── Config ────────────────────────────────────────────────────────────────

    public function getConfig(): JsonResponse
    {
        $config = MetaAiConfig::firstOrNew(['company_id' => Auth::user()->company_id]);
        return response()->json($config->makeHidden(['meta_app_secret', 'meta_access_token', 'meta_ai_api_key']));
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_enabled'              => 'boolean',
            'meta_ai_enabled'         => 'boolean',
            'meta_ai_model'           => 'nullable|string|max:100',
            'meta_ai_api_key'         => 'nullable|string',
            'analyze_on_message'      => 'boolean',
            'analyze_sentiment'       => 'boolean',
            'detect_buying_signals'   => 'boolean',
            'auto_qualify_leads'      => 'boolean',
            'auto_create_tasks'       => 'boolean',
            'hand_off_threshold'      => 'nullable|numeric|between:0,1',
            'inject_company_profile'  => 'boolean',
            'inject_services'         => 'boolean',
            'inject_pricing'          => 'boolean',
            'inject_past_conversations'=> 'boolean',
            'max_context_messages'    => 'nullable|integer|between:5,50',
        ]);

        // Encrypt meta_ai_api_key if provided
        if (!empty($data['meta_ai_api_key']) && !str_contains($data['meta_ai_api_key'], 'eyJ')) {
            $data['meta_ai_api_key'] = encrypt($data['meta_ai_api_key']);
        }

        $config = MetaAiConfig::updateOrCreate(
            ['company_id' => Auth::user()->company_id],
            $data
        );

        return response()->json([
            'message' => 'Config saved.',
            'data'    => $config->makeHidden(['meta_app_secret', 'meta_access_token', 'meta_ai_api_key']),
        ]);
    }

    // ── Analyses ──────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $analyses = ConversationAnalysis::where('company_id', $companyId)
            ->when($request->intent,    fn($q) => $q->where('detected_intent', $request->intent))
            ->when($request->sentiment, fn($q) => $q->where('sentiment', $request->sentiment))
            ->when($request->phone,     fn($q) => $q->where('phone', $request->phone))
            ->orderBy('analyzed_at', 'desc')
            ->paginate(30);

        return response()->json($analyses);
    }

    public function byContact(int $contactId): JsonResponse
    {
        $analyses = ConversationAnalysis::where('company_id', Auth::user()->company_id)
            ->where('contact_id', $contactId)
            ->orderBy('analyzed_at', 'desc')
            ->take(20)
            ->get();

        return response()->json($analyses);
    }

    // ── Lead scores ───────────────────────────────────────────────────────────

    public function leadScores(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $calc      = new LeadScoreCalculator();

        $contacts = Contact::where('company_id', $companyId)
            ->when($request->min_score, fn($q) => $q->where('lead_score', '>=', $request->min_score))
            ->when($request->max_score, fn($q) => $q->where('lead_score', '<=', $request->max_score))
            ->when($request->intent,    fn($q) => $q->where('detected_intent', $request->intent))
            ->when($request->sentiment, fn($q) => $q->where('last_sentiment', $request->sentiment))
            ->when($request->stage,     fn($q) => $q->where('lead_stage', $request->stage))
            ->orderBy('lead_score', 'desc')
            ->paginate(50);

        $contacts->getCollection()->transform(fn($c) => array_merge(
            $c->toArray(),
            ['score_label' => $calc->getScoreLabel($c->lead_score ?? 0)]
        ));

        return response()->json($contacts);
    }

    // ── Conversion events ─────────────────────────────────────────────────────

    public function conversionEvents(Request $request): JsonResponse
    {
        $events = LeadConversionEvent::where('company_id', Auth::user()->company_id)
            ->when($request->contact_id, fn($q) => $q->where('contact_id', $request->contact_id))
            ->when($request->event_type, fn($q) => $q->where('event_type', $request->event_type))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($events);
    }

    // ── Manual trigger ────────────────────────────────────────────────────────

    public function analyzeNow(Request $request, int $contactId): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $companyId = Auth::user()->company_id;
        $contact   = Contact::where('company_id', $companyId)->findOrFail($contactId);

        $config = MetaAiConfig::where('company_id', $companyId)->first();
        if (!$config || !$config->is_enabled) {
            return response()->json(['error' => 'Conversation Intelligence is not enabled.'], 422);
        }

        AnalyzeConversation::dispatch(
            $companyId, $contact->id, $contact->phone, $request->message
        )->onQueue('analysis');

        return response()->json(['message' => 'Analysis dispatched. Results will appear shortly.']);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $calc      = new LeadScoreCalculator();

        $totalAnalyzed = ConversationAnalysis::where('company_id', $companyId)->count();
        $avgScore      = Contact::where('company_id', $companyId)->where('lead_score', '>', 0)->avg('lead_score') ?? 0;
        $hotLeads      = Contact::where('company_id', $companyId)->where('lead_score', '>=', 76)->count();
        $conversions   = LeadConversionEvent::where('company_id', $companyId)
            ->where('event_type', 'converted')
            ->whereMonth('created_at', now()->month)
            ->count();

        $intentDistribution = ConversationAnalysis::where('company_id', $companyId)
            ->selectRaw('detected_intent, COUNT(*) as count')
            ->groupBy('detected_intent')
            ->pluck('count', 'detected_intent');

        return response()->json([
            'total_analyzed'      => $totalAnalyzed,
            'avg_lead_score'      => round($avgScore, 1),
            'hot_leads'           => $hotLeads,
            'conversions_month'   => $conversions,
            'intent_distribution' => $intentDistribution,
        ]);
    }

    // ── Contact intelligence profile ──────────────────────────────────────────

    public function contactProfile(int $contactId): JsonResponse
    {
        $companyId = Auth::user()->company_id;
        $contact   = Contact::where('company_id', $companyId)->findOrFail($contactId);
        $calc      = new LeadScoreCalculator();

        $recentAnalyses = ConversationAnalysis::where('company_id', $companyId)
            ->where('contact_id', $contactId)
            ->orderBy('analyzed_at', 'desc')
            ->take(5)
            ->get();

        $conversionEvents = LeadConversionEvent::where('company_id', $companyId)
            ->where('contact_id', $contactId)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $lastAnalysis          = $recentAnalyses->first();
        $recommendedNextAction = $lastAnalysis?->recommended_actions[0] ?? null;

        return response()->json([
            'contact'               => $contact,
            'lead_score'            => $contact->lead_score ?? 0,
            'lead_score_label'      => $calc->getScoreLabel($contact->lead_score ?? 0),
            'last_sentiment'        => $contact->last_sentiment,
            'detected_intent'       => $contact->detected_intent,
            'buying_signals_count'  => $contact->buying_signals_count ?? 0,
            'objections_count'      => $contact->objections_count ?? 0,
            'conversation_summary'  => $contact->conversation_summary,
            'recent_analyses'       => $recentAnalyses,
            'conversion_events'     => $conversionEvents,
            'recommended_next_action' => $recommendedNextAction,
        ]);
    }

    // ── Test analysis ─────────────────────────────────────────────────────────

    public function testAnalysis(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => 'required|string|max:2000',
            'contact_id' => 'nullable|integer',
        ]);

        $companyId = Auth::user()->company_id;
        $company   = Company::findOrFail($companyId);
        $config    = MetaAiConfig::where('company_id', $companyId)->first();

        if (!$config) {
            return response()->json(['error' => 'Conversation Intelligence not configured.'], 422);
        }

        $contact = $request->contact_id
            ? Contact::where('company_id', $companyId)->find($request->contact_id) ?? new Contact(['phone' => 'test', 'name' => 'Test User'])
            : new Contact(['phone' => 'test', 'name' => 'Test User', 'lead_score' => 0]);

        $analyzer = new \App\Services\MetaAI\ConversationAnalyzer(
            new \App\Services\MetaAI\CompanyContextBuilder(),
            new LeadScoreCalculator(),
        );

        // Temporarily enable for test even if analyze_on_message is off
        $config->analyze_on_message = true;

        try {
            $analysis = $analyzer->analyze($company, $contact, $contact->phone ?? 'test', $request->message, [], $config);
            return response()->json(['analysis' => $analysis]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Hot lead notifications polling ────────────────────────────────────────

    public function hotLeadAlerts(Request $request): JsonResponse
    {
        $since = $request->since
            ? \Carbon\Carbon::parse($request->since)
            : now()->subMinutes(5);

        $hotLeads = LeadConversionEvent::where('company_id', Auth::user()->company_id)
            ->whereIn('event_type', ['buying_signal_detected', 'auto_qualified', 'stage_changed'])
            ->where('created_at', '>=', $since)
            ->with('contact:id,name,phone')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return response()->json($hotLeads);
    }
}
