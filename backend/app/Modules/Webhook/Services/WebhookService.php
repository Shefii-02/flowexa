<?php

namespace App\Modules\Webhook\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Models\MessageBlacklist;
use App\Models\MessageLog;
use App\Models\WebhookLog;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use App\Modules\Webhook\DTOs\InboundMessageDTO;
use App\Modules\Webhook\DTOs\StatusUpdateDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    // ── STOP / unsubscribe keywords ────────────────────────────────────────
    private const OPT_OUT_KEYWORDS = [
        'stop', 'unsubscribe', 'optout', 'opt out', 'quit',
        'cancel', 'remove', 'end', 'block', 'no more',
    ];

    // ── Re-subscribe keywords ──────────────────────────────────────────────
    private const OPT_IN_KEYWORDS = [
        'start', 'subscribe', 'yes', 'optin', 'opt in',
        'resume', 'restart', 'begin',
    ];

    // ── Greeting keywords → send welcome menu ──────────────────────────────
    private const GREETING_KEYWORDS = [
        'hi', 'hello', 'hey', 'start', 'menu', 'hai', 'helo',
        'hii', 'hola', 'namaste', 'vanakkam', 'ഹലോ',
    ];

    public function __construct(
        private readonly LeadRepositoryInterface $leadRepository,
    ) {}

    // ─── Handle inbound message ───────────────────────────────────────────────
    public function handleInbound(Company $company, InboundMessageDTO $dto): void
    {
        // 1. Blacklist check — silent ignore, no reply, no lead
        $isBlacklisted = MessageBlacklist::where('company_id', $company->id)
            ->where('phone', $dto->phone)
            ->exists();

        if ($isBlacklisted) {
            Log::info("Blacklisted number {$dto->phone} ignored for company {$company->id}");
            return;
        }

        // 2. Find or create contact
        $contact = $this->resolveContact($company, $dto);

        // 3. Log inbound message with full content
        MessageLog::create([
            'company_id'    => $company->id,
            'contact_id'    => $contact->id,
            'wa_message_id' => $dto->messageId,
            'direction'     => 'inbound',
            'type'          => $dto->type,
            'phone'         => $dto->phone,
            'content'       => $this->extractContent($dto),
            'status'        => 'received',
            'cost'          => 0,
        ]);

        $contact->update(['last_message_at' => now()]);

        // 4. STOP / opt-out handling (before any flow routing)
        if ($dto->type === 'text') {
            $msgText = strtolower(trim($dto->text ?? ''));

            if (in_array($msgText, self::OPT_OUT_KEYWORDS)) {
                $contact->update(['opted_in' => false, 'opted_out_at' => now()]);
                $this->sendText(
                    $company,
                    $dto->phone,
                    "You have been unsubscribed. ✅\n\nYou will no longer receive messages from us.\n\nReply *START* anytime to resubscribe."
                );
                Log::info("Contact {$contact->id} opted out via keyword: {$msgText}");
                return;
            }

            // Re-subscribe
            if (in_array($msgText, self::OPT_IN_KEYWORDS) && !$contact->opted_in) {
                $contact->update(['opted_in' => true, 'opted_out_at' => null]);
                $this->sendText(
                    $company,
                    $dto->phone,
                    "Welcome back! 👋 You have been resubscribed successfully. ✅"
                );
                // Continue — send welcome menu below
            }
        }

        // 5. Check opted-in status — skip opted-out contacts
        if (!$contact->fresh()->opted_in) {
            Log::info("Contact {$contact->id} is opted out — skipping flow routing");
            return;
        }

        // 6. Resolve active flow builder
        $builder = $this->resolveFlowBuilder($company, $dto->text ?? '');

        // 7. Check active flow session (mid-conversation)
        $session = FlowSession::where('company_id', $company->id)
            ->where('phone', $dto->phone)
            ->where('expires_at', '>', now())
            ->first();

        // 8. Interactive reply → match flow node
        if ($dto->type === 'interactive' && $dto->replyId) {
            $this->handleFlowReply($company, $contact, $dto, $builder?->id);
            return;
        }

        // 9. Text greeting OR new conversation → send welcome menu
        if ($dto->type === 'text' && $this->isGreeting($dto->text)) {
            // Clear existing session on new greeting
            $session?->delete();
            $this->sendWelcomeMenu($company, $dto->phone, $builder?->id);
            return;
        }

        // 10. Mid-session text → try to match current node's children
        if ($session && $dto->type === 'text') {
            $matched = $this->matchTextToNode($company, $contact, $dto, $session, $builder?->id);
            if ($matched) return;
        }

        // 11. Default fallback response
        $this->sendText(
            $company,
            $dto->phone,
            "Thank you for your message! 😊 Our team will get back to you shortly.\n\nReply *menu* to see our options."
        );
    }

    // ─── Handle status update (sent/delivered/read/failed) ────────────────────
    public function handleStatusUpdate(Company $company, StatusUpdateDTO $dto): void
    {
        // Update message log
        $log = MessageLog::where('wa_message_id', $dto->waMessageId)->first();
        if ($log) {
            $updates = ['status' => $dto->status];
            if ($dto->status === 'delivered') $updates['delivered_at'] = now();
            if ($dto->status === 'read')      $updates['read_at']      = now();
            $log->update($updates);
        }

        // Update campaign contact status
        if (in_array($dto->status, ['delivered', 'read', 'failed'])) {
            $cc = \App\Models\CampaignContact::where('wa_message_id', $dto->waMessageId)->first();
            if ($cc) {
                $cc->update([
                    'status'       => $dto->status,
                    'delivered_at' => $dto->status === 'delivered' ? now() : $cc->delivered_at,
                    'read_at'      => $dto->status === 'read'      ? now() : $cc->read_at,
                ]);

                // Increment campaign counter
                \App\Models\Campaign::where('id', $cc->campaign_id)
                    ->increment($dto->status === 'failed' ? 'failed' : $dto->status);
            }
        }
    }

    // ─── Resolve which flow builder to use ────────────────────────────────────
    // Priority: keyword → season (date range) → default active
    private function resolveFlowBuilder(Company $company, string $text): ?FlowBuilder
    {
        $keyword = strtolower(trim($text));
        $now     = now();

        // 1. Keyword-triggered flow
        $keywordBuilders = FlowBuilder::where('company_id', $company->id)
            ->where('trigger_type', 'keyword')
            ->where('is_active', true)
            ->get();

        foreach ($keywordBuilders as $builder) {
            $keywords = json_decode($builder->trigger_keywords ?? '[]', true);
            if (in_array($keyword, array_map('strtolower', $keywords))) {
                Log::info("Keyword flow triggered: builder {$builder->id} for keyword '{$keyword}'");
                return $builder;
            }
        }

        // 2. Season/date-range flow
        $seasonBuilder = FlowBuilder::where('company_id', $company->id)
            ->where('trigger_type', 'season')
            ->where('is_active', true)
            ->where('active_from',  '<=', $now)
            ->where('active_until', '>=', $now)
            ->first();

        if ($seasonBuilder) {
            Log::info("Season flow active: builder {$seasonBuilder->id}");
            return $seasonBuilder;
        }

        // 3. Default flow
        return FlowBuilder::where('company_id', $company->id)
            ->where('trigger_type', 'default')
            ->where('is_active', true)
            ->first();
    }

    // ─── Match reply_id to flow node → send response → maybe create lead ─────
    private function handleFlowReply(
        Company $company,
        Contact $contact,
        InboundMessageDTO $dto,
        ?int $builderId
    ): void {
        $query = FlowNode::where('company_id', $company->id)
            ->where('reply_id', $dto->replyId)
            ->where('is_active', true);

        if ($builderId) {
            $query->where('flow_builder_id', $builderId);
        }

        $node = $query->first();

        if (!$node) {
            Log::warning("No flow node found for reply_id={$dto->replyId} company={$company->id}");
            $this->sendText($company, $dto->phone, "Sorry, that option is not currently available. Reply *menu* to start again.");
            return;
        }

        $node->increment('trigger_count');

        // Update flow session
        FlowSession::updateOrCreate(
            ['company_id' => $company->id, 'phone' => $dto->phone],
            [
                'contact_id'      => $contact->id,
                'current_node_id' => $node->id,
                'flow_builder_id' => $node->flow_builder_id,
                'context'         => [
                    'last_reply_id'  => $dto->replyId,
                    'last_title'     => $dto->replyTitle,
                    'history'        => [$dto->replyId],
                ],
                'expires_at' => now()->addHours(24),
            ]
        );

        // Send node message + children
        $this->sendNodeResponse($company, $dto->phone, $node);

        // Auto-create lead if node has lead_category
        if ($node->lead_category) {
            $this->autoCreateLead($company, $contact, $node);
        }
    }

    // ─── Try matching text input to current node's children ───────────────────
    private function matchTextToNode(
        Company $company,
        Contact $contact,
        InboundMessageDTO $dto,
        FlowSession $session,
        ?int $builderId
    ): bool {
        if (!$session->current_node_id) return false;

        $currentNode = FlowNode::find($session->current_node_id);
        if (!$currentNode) return false;

        // Try matching text to child node titles
        $children = $currentNode->children()
            ->where('is_active', true)
            ->get();

        $msgText = strtolower(trim($dto->text ?? ''));

        $matched = $children->first(function ($child) use ($msgText) {
            return strtolower(trim($child->title)) === $msgText
                || strtolower(trim($child->reply_id)) === $msgText;
        });

        if ($matched) {
            $fakeDto = new InboundMessageDTO(
                phone:           $dto->phone,
                waId:            $dto->waId,
                messageId:       $dto->messageId,
                type:            'interactive',
                text:            $dto->text,
                interactiveType: 'button_reply',
                replyId:         $matched->reply_id,
                replyTitle:      $matched->title,
                rawPayload:      $dto->rawPayload,
                caption:         $dto->caption,
            );
            $this->handleFlowReply($company, $contact, $fakeDto, $builderId);
            return true;
        }

        // Re-send current node options
        $this->sendNodeResponse($company, $dto->phone, $currentNode);
        return true;
    }

    // ─── Send welcome menu (root node) ────────────────────────────────────────
    private function sendWelcomeMenu(Company $company, string $phone, ?int $builderId): void
    {
        $query = FlowNode::where('company_id', $company->id)
            ->whereNull('parent_id')
            ->where('is_active', true);

        if ($builderId) {
            $query->where('flow_builder_id', $builderId);
        }

        $root = $query->orderBy('sort_order')->first();

        if (!$root) {
            $this->sendText($company, $phone, "Welcome! 👋 How can we help you today?");
            return;
        }

        $root->increment('trigger_count');
        $this->sendNodeResponse($company, $phone, $root);
    }

    // ─── Build and send node response ────────────────────────────────────────
    private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
    {
        $children = $node->children()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($children->isEmpty() || $node->type === 'text') {
            $this->sendText($company, $phone, $node->message);
            return;
        }

        if ($node->type === 'button' && $children->count() <= 3) {
            $this->sendButton($company, $phone, $node->message, $children);
        } else {
            $this->sendList($company, $phone, $node->message, $children);
        }
    }

    // ─── Auto-create lead from flow ───────────────────────────────────────────
    private function autoCreateLead(Company $company, Contact $contact, FlowNode $node): void
    {
        // Skip if active (non-terminal) lead already exists
        $existing = $this->leadRepository->findByContact($contact->id, $company->id);
        if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
            Log::info("Lead already active for contact {$contact->id} — skipping auto-create");
            return;
        }

        $dto = CreateLeadDTO::fromFlow(
            contactId:  $contact->id,
            flowNodeId: $node->id,
            category:   $node->lead_category,
        );

        try {
            $lead = $this->leadRepository->create($company->id, $dto);
            Log::info("Auto-created lead {$lead->id} for contact {$contact->id} from flow node {$node->id}");
        } catch (\Exception $e) {
            Log::error("Auto-create lead failed: " . $e->getMessage());
        }
    }

    // ─── Resolve or create contact ────────────────────────────────────────────
    private function resolveContact(Company $company, InboundMessageDTO $dto): Contact
    {
        return Contact::firstOrCreate(
            ['company_id' => $company->id, 'phone' => $dto->phone],
            ['wa_id' => $dto->waId, 'opted_in' => true]
        );
    }

    // ─── Extract readable content from message ────────────────────────────────
    private function extractContent(InboundMessageDTO $dto): string
    {
        return match ($dto->type) {
            'text'        => $dto->text ?? '',
            'interactive' => $dto->replyTitle ?? $dto->replyId ?? '[interactive reply]',
            'image'       => '[Image]' . ($dto->caption ? ': ' . $dto->caption : ''),
            'audio'       => '[Voice note]',
            'video'       => '[Video]',
            'document'    => '[Document]',
            'sticker'     => '[Sticker]',
            'location'    => '[Location]',
            default       => '[' . $dto->type . ']',
        };
    }

    // ─── WhatsApp API helpers ─────────────────────────────────────────────────
    private function sendText(Company $company, string $phone, string $text): void
    {
        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => ['body' => $text, 'preview_url' => false],
        ]);
    }

    private function sendButton(Company $company, string $phone, string $body, $children): void
    {
        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => [
                    'buttons' => $children->map(fn($c) => [
                        'type'  => 'reply',
                        'reply' => [
                            // Meta: button id max 256 chars, title max 20 chars
                            'id'    => mb_substr($c->reply_id, 0, 256),
                            'title' => mb_substr($c->title, 0, 20),
                        ],
                    ])->values()->all(),
                ],
            ],
        ]);
    }

    private function sendList(Company $company, string $phone, string $body, $children): void
    {
        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'list',
                'body' => ['text' => $body],
                'action' => [
                    'button'   => 'View Options',
                    'sections' => [[
                        'title' => 'Options',
                        'rows'  => $children->map(fn($c) => [
                            // Meta: row id max 200 chars, title max 24 chars, description max 72 chars
                            'id'          => mb_substr($c->reply_id, 0, 200),
                            'title'       => mb_substr($c->title, 0, 24),
                            'description' => mb_substr($c->message ?? '', 0, 72),
                        ])->values()->all(),
                    ]],
                ],
            ],
        ]);
    }

    // ─── POST to Meta Graph API ───────────────────────────────────────────────
    private function dispatch(Company $company, array $payload): void
    {
        if (!$company->wa_phone_id || !$company->wa_access_token) {
            Log::error("Company {$company->id} has no WA credentials — cannot send message");
            return;
        }

        try {
            // Decrypt token (stored encrypted in DB)
            $token = $company->wa_access_token;
            try { $token = decrypt($token); } catch (\Exception) { /* already plain */ }

            $response = Http::withToken($token)
                ->timeout(10)
                ->post("https://graph.facebook.com/v21.0/{$company->wa_phone_id}/messages", $payload);

            if ($response->successful()) {
                $waId = $response->json('messages.0.id');

                MessageLog::create([
                    'company_id'    => $company->id,
                    'wa_message_id' => $waId,
                    'direction'     => 'outbound',
                    'type'          => $payload['type'],
                    'phone'         => $payload['to'],
                    'content'       => $this->extractOutboundContent($payload),
                    'status'        => 'sent',
                    'cost'          => 1,
                ]);

                Log::info("WA message sent: waId={$waId} to={$payload['to']} company={$company->id}");
            } else {
                $error = $response->json('error.message') ?? 'Unknown error';
                $code  = $response->json('error.code');
                Log::error("WA send failed: [{$code}] {$error}", [
                    'company'  => $company->id,
                    'to'       => $payload['to'],
                    'response' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error("WA dispatch exception: " . $e->getMessage(), ['company' => $company->id]);
        }
    }

    private function extractOutboundContent(array $payload): string
    {
        return match ($payload['type']) {
            'text'        => $payload['text']['body'] ?? '',
            'interactive' => match ($payload['interactive']['type'] ?? '') {
                'button' => $payload['interactive']['body']['text'] ?? '[button message]',
                'list'   => $payload['interactive']['body']['text'] ?? '[list message]',
                default  => '[interactive]',
            },
            default => '[' . $payload['type'] . ']',
        };
    }

    private function isGreeting(?string $text): bool
    {
        if (!$text) return false;
        return in_array(strtolower(trim($text)), self::GREETING_KEYWORDS);
    }
}


// class WebhookService
// {
//     public function __construct(
//         private readonly LeadRepositoryInterface $leadRepository,
//     ) {}

//     // ─── Handle inbound message ───────────────────────────────────────────────
//     public function handleInbound(Company $company, InboundMessageDTO $dto): void
//     {
//         // 1. Find or create contact
//         $contact = $this->resolveContact($company, $dto);

//         // 2. Log inbound message
//         MessageLog::create([
//             'company_id' => $company->id,
//             'contact_id' => $contact->id,
//             'wa_message_id' => $dto->messageId,
//             'direction'  => 'inbound',
//             'type'       => $dto->type,
//             'phone'      => $dto->phone,
//             'content'    => $dto->rawPayload ?? [],
//             'status'     => 'delivered',
//             'cost'       => 0,
//         ]);

//         // Update contact last_message_at
//         $contact->update(['last_message_at' => now()]);

//         // 3. If text message with greeting keywords → send welcome menu
//         if ($dto->type === 'text' && $this->isGreeting($dto->text)) {
//             $this->sendWelcomeMenu($company, $dto->phone);
//             return;
//         }

//         // 4. If interactive reply → match flow node
//         if ($dto->type === 'interactive' && $dto->replyId) {
//             $this->handleFlowReply($company, $contact, $dto);
//             return;
//         }

//         // 5. Default: echo acknowledgment
//         $this->sendTextMessage($company, $dto->phone, "Thank you for your message! We'll get back to you soon.");
//     }

//     // ─── Handle status update (sent/delivered/read/failed) ────────────────────
//     public function handleStatusUpdate(Company $company, StatusUpdateDTO $dto): void
//     {
//         // Update message log
//         $log = MessageLog::where('wa_message_id', $dto->waMessageId)->first();
//         if ($log) {
//             $updates = ['status' => $dto->status];
//             if ($dto->status === 'delivered') $updates['delivered_at'] = now();
//             if ($dto->status === 'read')      $updates['read_at']      = now();
//             $log->update($updates);
//         }

//         // Update campaign contact status
//         if (in_array($dto->status, ['delivered', 'read'])) {
//             $cc = \App\Models\CampaignContact::where('wa_message_id', $dto->waMessageId)->first();
//             if ($cc) {
//                 $cc->update([
//                     'status'       => $dto->status,
//                     'delivered_at' => $dto->status === 'delivered' ? now() : $cc->delivered_at,
//                     'read_at'      => $dto->status === 'read'      ? now() : $cc->read_at,
//                 ]);

//                 // Increment campaign counter
//                 \App\Models\Campaign::where('id', $cc->campaign_id)->increment($dto->status);
//             }
//         }
//     }

//     // ─── Match reply_id to flow node → send response → maybe create lead ─────
//     private function handleFlowReply(Company $company, Contact $contact, InboundMessageDTO $dto): void
//     {
//         // Find matching active node
//         $node = FlowNode::where('company_id', $company->id)
//             ->where('reply_id', $dto->replyId)
//             ->where('is_active', true)
//             ->first();

//         if (!$node) {
//             $this->sendTextMessage($company, $dto->phone, "Sorry, that option is not available right now.");
//             return;
//         }

//         // Increment trigger count
//         $node->increment('trigger_count');

//         // Update or create flow session
//         FlowSession::updateOrCreate(
//             ['company_id' => $company->id, 'phone' => $dto->phone],
//             [
//                 'contact_id'      => $contact->id,
//                 'current_node_id' => $node->id,
//                 'context'         => ['last_reply_id' => $dto->replyId, 'last_title' => $dto->replyTitle],
//                 'expires_at'      => now()->addHours(24),
//             ]
//         );

//         // Send node message + children as interactive
//         $this->sendNodeResponse($company, $dto->phone, $node);

//         // Auto-create lead if node has a lead_category
//         if ($node->lead_category) {
//             $this->autoCreateLead($company, $contact, $node);
//         }
//     }

//     // ─── Send node message with children as buttons/list ─────────────────────
//     private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
//     {
//         $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

//         if ($children->isEmpty() || $node->type === 'text') {
//             $this->sendTextMessage($company, $phone, $node->message);
//             return;
//         }

//         if ($node->type === 'button' && $children->count() <= 3) {
//             $this->sendButtonMessage($company, $phone, $node->message, $children);
//         } else {
//             $this->sendListMessage($company, $phone, $node->message, $children);
//         }
//     }

//     // ─── Auto-create lead from flow ───────────────────────────────────────────
//     private function autoCreateLead(Company $company, Contact $contact, FlowNode $node): void
//     {
//         // Skip if active lead already exists for this contact
//         $existing = $this->leadRepository->findByContact($contact->id, $company->id);
//         if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
//             return;
//         }

//         $dto = CreateLeadDTO::fromFlow(
//             contactId:  $contact->id,
//             flowNodeId: $node->id,
//             category:   $node->lead_category,
//         );

//         try {
//             $this->leadRepository->create($company->id, $dto);
//             Log::info("Auto-created lead for contact {$contact->id} from flow node {$node->id}");
//         } catch (\Exception $e) {
//             Log::error("Auto-create lead failed: " . $e->getMessage());
//         }
//     }

//     // ─── Resolve or create contact ────────────────────────────────────────────
//     private function resolveContact(Company $company, InboundMessageDTO $dto): Contact
//     {
//         return Contact::firstOrCreate(
//             ['company_id' => $company->id, 'phone' => $dto->phone],
//             ['wa_id' => $dto->waId, 'opted_in' => true]
//         );
//     }

//     // ─── WhatsApp API helpers ─────────────────────────────────────────────────
//     private function sendTextMessage(Company $company, string $phone, string $text): void
//     {
//         $this->sendToWhatsApp($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'text',
//             'text'              => ['body' => $text],
//         ]);
//     }

//     private function sendButtonMessage(Company $company, string $phone, string $body, $children): void
//     {
//         $this->sendToWhatsApp($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => [
//                 'type' => 'button',
//                 'body' => ['text' => $body],
//                 'action' => [
//                     'buttons' => $children->map(fn($c) => [
//                         'type'  => 'reply',
//                         'reply' => ['id' => $c->reply_id, 'title' => $c->title],
//                     ])->values()->all(),
//                 ],
//             ],
//         ]);
//     }

//     private function sendListMessage(Company $company, string $phone, string $body, $children): void
//     {
//         $this->sendToWhatsApp($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => [
//                 'type' => 'list',
//                 'body' => ['text' => $body],
//                 'action' => [
//                     'button'   => 'View Options',
//                     'sections' => [[
//                         'title' => 'Options',
//                         'rows'  => $children->map(fn($c) => [
//                             'id'          => $c->reply_id,
//                             'title'       => $c->title,
//                             'description' => mb_substr($c->message, 0, 72),
//                         ])->values()->all(),
//                     ]],
//                 ],
//             ],
//         ]);
//     }

//     private function sendWelcomeMenu(Company $company, string $phone): void
//     {
//         $roots = FlowNode::where('company_id', $company->id)
//             ->whereNull('parent_id')
//             ->where('is_active', true)
//             ->orderBy('sort_order')
//             ->get();

//         if ($roots->isEmpty()) {
//             $this->sendTextMessage($company, $phone, "Welcome! How can we help you today?");
//             return;
//         }

//         // Send first root node
//         $this->sendNodeResponse($company, $phone, $roots->first());
//     }

//     private function sendToWhatsApp(Company $company, array $payload): void
//     {
//         if (!$company->wa_phone_id || !$company->wa_access_token) return;

//         try {
//             $response = Http::withToken(decrypt($company->wa_access_token))
//                 ->timeout(10)
//                 ->post("https://graph.facebook.com/v21.0/{$company->wa_phone_id}/messages", $payload);

//             if ($response->successful()) {
//                 $waId = $response->json('messages.0.id');
//                 MessageLog::create([
//                     'company_id'    => $company->id,
//                     'wa_message_id' => $waId,
//                     'direction'     => 'outbound',
//                     'type'          => $payload['type'],
//                     'phone'         => $payload['to'],
//                     'content'       => $payload,
//                     'status'        => 'sent',
//                     'cost'          => 0,
//                 ]);
//             } else {
//                 Log::error('WhatsApp send failed', ['response' => $response->json()]);
//             }
//         } catch (\Exception $e) {
//             Log::error('WhatsApp send exception: ' . $e->getMessage());
//         }
//     }

//     private function isGreeting(?string $text): bool
//     {
//         if (!$text) return false;
//         $greetings = ['hi', 'hello', 'hey', 'start', 'menu', 'hai', 'helo'];
//         return in_array(strtolower(trim($text)), $greetings);
//     }
// }





