<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * WhatsAppController
 *
 * Single-file controller for testing WhatsApp Business Platform (Graph API)
 * sending — contacts, labels, templates, and a real send() that calls Meta's
 * API instead of driving a browser DOM.
 *
 * Env vars needed for live sending (send() will run in "dry run" mode without them):
 *   WHATSAPP_PHONE_NUMBER_ID=
 *   WHATSAPP_WABA_ID=
 *   WHATSAPP_ACCESS_TOKEN=
 *   WHATSAPP_GRAPH_VERSION=v20.0
 */
class ExtensionController extends Controller
{
    // ------------------------------------------------------------------
    // Mock data — swap for real models (Contact::class, Label::class, etc.)
    // once you're past the test stage.
    // ------------------------------------------------------------------

    private array $mockUser = [
        'email' => 'admin@demo.test',
        'password' => 'admin123', // plaintext only because this is throwaway test data
    ];

    private array $mockContacts = [
        ['id' => 'c1', 'name' => 'Shefii', 'number' => '+918075261300', 'labels' => ['l1', 'l2']],
        ['id' => 'c2', 'name' => 'Mom', 'number' => '+918075227300', 'labels' => ['l2']],
        ['id' => 'c3', 'name' => 'Sneha Sankar', 'number' => '+918078903699', 'labels' => ['l3']],
        ['id' => 'c4', 'name' => 'Lakshmi', 'number' => '+919061383673', 'labels' => ['l3']],
        ['id' => 'c5', 'name' => 'Univexa', 'number' => '+919846361644', 'labels' => ['l2']],
    ];

    private array $mockLabels = [
        ['id' => 'l1', 'name' => 'VIP Enterprise Clients'],
        ['id' => 'l2', 'name' => 'Pending Follow-up'],
        ['id' => 'l3', 'name' => 'New Inbound Leads'],
    ];

    private array $mockTemplates = [
        [
            'id' => 26,
            'company_id' => 6,
            'name' => 'test_malayalam_flow_copy_6d64',
            'language' => 'ml',
            'body' => "നമസ്കാരം {{1}},\n\nനിങ്ങളുടെ അഭ്യർത്ഥന വിജയകരമായി ലഭിച്ചു.\n\nഞങ്ങളുടെ ടീം ഉടൻ തന്നെ നിങ്ങളുമായി ബന്ധപ്പെടുന്നതാണ്.\n\nനന്ദി.",
            'body_examples' => ['shefii'],
            'buttons' => [
                ['text' => 'STOP', 'type' => 'QUICK_REPLY'],
                ['text' => 'Location', 'type' => 'URL', 'url' => 'https://maps.app.goo.gl/Mi7nuToJTGrcdNFe7'],
                ['text' => 'Call us', 'type' => 'PHONE_NUMBER', 'phone_number' => '+919846366783'],
            ],
            'category' => 'MARKETING',
            'footer' => 'Reply STOP to unsubscribe',
            'header_format' => 'IMAGE',
            'header_sample_url' => 'https://flowexa-api.univexa.in/storage/template-headers/dup_6a7145e5b604e.png',
            'status' => 'approved',
            'wa_template_id' => '1439591617998084',
        ],
    ];

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (
            strtolower($data['email']) !== strtolower($this->mockUser['email']) ||
            $data['password'] !== $this->mockUser['password']
        ) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 401);
        }

        // Swap for a real user model + JWTAuth::fromUser($user) once mock auth is retired.
        // $token = JWTAuth::claims(['email' => $data['email']])
        //     ->fromUser((object) ['email' => $data['email']]) ?? null;

        $token="asdsdasdasdasdasdsdasd";

        // If you're not wiring a real JWT guard yet, a plain signed token works fine for testing:
        if (!$token) {
            $token = base64_encode(json_encode([
                'email' => $data['email'],
                'exp' => now()->addHours(12)->timestamp,
            ]));
        }

        return response()->json(['success' => true, 'token' => $token]);
    }

    // ------------------------------------------------------------------
    // Data endpoints
    // ------------------------------------------------------------------

    public function contacts()
    {
        return response()->json($this->mockContacts);
    }

    public function labels()
    {
        return response()->json($this->mockLabels);
    }

    public function templates()
    {
        return response()->json($this->mockTemplates);
    }

    public function contactsByLabels(Request $request)
    {
        $data = $request->validate([
            'labelIds' => 'required|array',
            'labelIds.*' => 'string',
        ]);

        $filtered = collect($this->mockContacts)
            ->filter(fn ($contact) => count(array_intersect($contact['labels'], $data['labelIds'])) > 0)
            ->unique('id')
            ->values();

        return response()->json($filtered);
    }

    // ------------------------------------------------------------------
    // Send — calls Meta's Graph API directly. No browser, no DOM,
    // no click simulation. Rate limiting and delivery are handled by
    // Meta's platform, not by a setTimeout loop.
    // ------------------------------------------------------------------

    public function send(Request $request)
    {
        $data = $request->validate([
            'to' => 'required|array|min:1',
            'to.*' => 'string',
            'template_name' => 'required|string',
            'language' => 'sometimes|string',
            'components' => 'sometimes|array', // Graph API template components (header/body params)
        ]);

        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');
        $graphVersion = config('services.whatsapp.graph_version', 'v20.0');

        // Dry-run mode when credentials aren't configured yet — lets you test
        // the endpoint shape before wiring real Meta credentials.
        if (!$phoneNumberId || !$accessToken) {
            return response()->json([
                'mode' => 'dry_run',
                'message' => 'WHATSAPP_PHONE_NUMBER_ID / WHATSAPP_ACCESS_TOKEN not set — no messages were sent.',
                'would_send_to' => $data['to'],
                'template_name' => $data['template_name'],
            ]);
        }

        $results = [];

        foreach ($data['to'] as $recipient) {
            $response = Http::withToken($accessToken)
                ->post("https://graph.facebook.com/{$graphVersion}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $recipient,
                    'type' => 'template',
                    'template' => array_filter([
                        'name' => $data['template_name'],
                        'language' => ['code' => $data['language'] ?? 'en_US'],
                        'components' => $data['components'] ?? null,
                    ]),
                ]);

            $results[] = [
                'to' => $recipient,
                'status' => $response->successful() ? 'accepted' : 'failed',
                'response' => $response->json(),
            ];
        }

        return response()->json(['results' => $results]);
    }
}
