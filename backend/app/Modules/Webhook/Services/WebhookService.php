<?php

namespace App\Modules\Webhook\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Models\MessageBlacklist;
use App\Models\MessageLog;
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
        'stop',
        'unsubscribe',
        'optout',
        'opt out',
        'quit',
        'cancel',
        'remove',
        'end',
        'block',
        'no more',
    ];

    // ── Re-subscribe keywords ──────────────────────────────────────────────
    private const OPT_IN_KEYWORDS = [
        'start',
        'subscribe',
        'yes',
        'optin',
        'opt in',
        'resume',
        'restart',
        'begin',
    ];

    // ── Greeting keywords → send welcome menu ──────────────────────────────
    private const GREETING_KEYWORDS = [
        'hi',
        'hello',
        'hey',
        'start',
        'menu',
        'hai',
        'helo',
        'hii',
        'hola',
        'namaste',
        'vanakkam',
        'ഹലോ',
    ];

    // Options row cap enforced by WhatsApp for interactive lists
    private const MAX_LIST_ROWS = 10;

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
        $log = MessageLog::where('wa_message_id', $dto->waMessageId)->first();
        if ($log) {
            $updates = ['status' => $dto->status];
            if ($dto->status === 'delivered') $updates['delivered_at'] = now();
            if ($dto->status === 'read')      $updates['read_at']      = now();
            $log->update($updates);
        }

        if (in_array($dto->status, ['delivered', 'read', 'failed'])) {
            $cc = \App\Models\CampaignContact::where('wa_message_id', $dto->waMessageId)->first();
            if ($cc) {
                $cc->update([
                    'status'       => $dto->status,
                    'delivered_at' => $dto->status === 'delivered' ? now() : $cc->delivered_at,
                    'read_at'      => $dto->status === 'read'      ? now() : $cc->read_at,
                ]);

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

        $keywordBuilders = FlowBuilder::where('company_id', $company->id)
            ->where('trigger_type', 'keyword')
            ->where('is_active', true)
            ->get();

        foreach ($keywordBuilders as $builder) {
            // $keywords = json_decode($builder->trigger_keywords ?? '[]', true);
            $keywords = $builder->trigger_keywords ?? [];
            if (in_array($keyword, array_map('strtolower', $keywords))) {
                Log::info("Keyword flow triggered: builder {$builder->id} for keyword '{$keyword}'");
                return $builder;
            }
        }

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

    Log::info("Handling flow reply: company={$company->id} contact={$contact->id} reply_id={$dto->replyId} builder_id={$builderId}");
        $query = FlowNode::where('company_id', $company->id)
            ->where('reply_id', $dto->replyId)
            ->where('is_active', true);

        if ($builderId) {
            $query->where('flow_builder_id', $builderId);
        }

        $node = $query->first();

        if (!$node) {
            Log::warning("No flow node found for reply_id={$dto->replyId} company={$company->id}");
            $this->sendFallbackMessage($company, $dto->phone);
            return;
        }

        $node->increment('trigger_count');

        Log::info("Flow node triggered: node={$node->id} reply_id={$dto->replyId} company={$company->id}");
        Log::info($node);

        FlowSession::updateOrCreate(
            ['company_id' => $company->id, 'phone' => $dto->phone],
            [
                'contact_id'      => $contact->id,
                'current_node_id' => $node->id,
                'flow_builder_id' => $node->flow_builder_id,
                'context'         => [
                    'last_reply_id' => $dto->replyId,
                    'last_title'    => $dto->replyTitle,
                    'history'       => [$dto->replyId],
                ],
                'expires_at' => now()->addHours(24),
            ]
        );

        $this->sendNodeResponse($company, $dto->phone, $node);

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

        $children = $currentNode->children()->where('is_active', true)->get();
        $msgText  = strtolower(trim($dto->text ?? ''));

        $matched = $children->first(function ($child) use ($msgText) {
            return strtolower(trim($child->title)) === $msgText
                || strtolower(trim($child->reply_id)) === $msgText;
        });

        if ($matched) {
            $fakeDto = new InboundMessageDTO(
                phone: $dto->phone,
                waId: $dto->waId,
                messageId: $dto->messageId,
                type: 'interactive',
                text: $dto->text,
                interactiveType: 'button_reply',
                replyId: $matched->reply_id,
                replyTitle: $matched->title,
                rawPayload: $dto->rawPayload,
                caption: $dto->caption,
            );
            $this->handleFlowReply($company, $contact, $fakeDto, $builderId);
            return true;
        }

        // Re-send current node options instead of matching nothing
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

    // ─── Build and send node response ──────────────────────────────────────────
    // Order of precedence: dynamic (API-driven) → multi-message → single rich media
    // → leaf text/media → button/list with static children.
    private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
    {
        // ── Dynamic node: options fetched live from an external API ──────────
        if ($node->is_dynamic && $node->dynamic_api_url) {
            if ($node->message) {
                $this->sendText($company, $phone, $node->message);
                usleep(300000);
            }

            $options = $this->resolveDynamicOptions($node);

            if ($options === null) {
                $this->sendFallbackMessage($company, $phone);
                return;
            }

            $this->sendDynamicOptions($company, $phone, $node, $options);
            return;
        }

        $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

        // ── Multi-message node: send each block one-by-one, then options last ──
        if ($node->hasMultipleMessages()) {
            $this->sendMultipleMessages($company, $phone, $node->multi_messages);

            if ($children->isNotEmpty()) {
                usleep(500000); // 0.5s gap before the buttons/list
                if ($node->type === 'button' && $children->count() <= 3) {
                    $this->sendButton($company, $phone, '👇 Please select an option:', $children);
                } else {
                    $this->sendList($company, $phone, '👇 Please select an option:', $children);
                }
            }
            return;
        }

        // ── Single rich-media node (no multi_messages) ──────────────────────────
        if ($node->media_type === 'audio' && $node->media_url) {
            $this->sendAudio($company, $phone, $node->media_url);
            if ($node->message) {
                usleep(300000);
                $this->sendText($company, $phone, $node->message);
            }
            return;
        }

        if ($node->media_type === 'location' && $node->location_lat) {
            $this->sendLocation($company, $phone, $node);
            return;
        }

        if ($children->isEmpty()) {
            if ($node->media_type && $node->media_url) {
                $this->sendSingleMedia($company, $phone, $node->media_type, $node->media_url, $node->media_caption, $node->media_filename);
            } else {
                $this->sendText($company, $phone, $node->message ?: 'Thanks for your reply!');
            }
            return;
        }

        // ── Button / list with static children ──────────────────────────────────
        if ($node->type === 'button' && $children->count() <= 3) {
            $this->sendButtonWithOptionalMedia($company, $phone, $node, $children);
        } else {
            $this->sendList($company, $phone, $node->message, $children);
        }
    }

    // ─── Fetch + normalize options for a dynamic node ──────────────────────────
    // Returns null on any failure (bad response, timeout, empty payload) so the
    // caller can fall back to a friendly message instead of a dead end.
    private function resolveDynamicOptions(FlowNode $node): ?array
    {
        try {
            $headers = [];
            if ($node->dynamic_api_headers) {
                $decoded = json_decode($node->dynamic_api_headers, true);
                if (is_array($decoded)) $headers = $decoded;
            }

            $request = Http::withHeaders($headers)->timeout(8);
            $method  = strtoupper($node->dynamic_api_method ?? 'GET');

            $response = $method === 'POST'
                ? $request->post($node->dynamic_api_url)
                : $request->get($node->dynamic_api_url);

            if (!$response->successful()) {
                Log::warning("Dynamic node API failed: node={$node->id} status={$response->status()}");
                return null;
            }

            $data = $response->json();
            $rows = (is_array($data) && array_is_list($data)) ? $data : ($data['data'] ?? $data['results'] ?? []);

            if (!is_array($rows) || empty($rows)) {
                Log::warning("Dynamic node API returned no rows: node={$node->id}");
                return null;
            }

            $labelField = $node->dynamic_label_field ?: 'name';
            $valueField = $node->dynamic_value_field ?: 'id';

            $options = collect($rows)->map(fn($row) => [
                'title'       => (string) ($row[$labelField] ?? 'Option'),
                'reply_id'    => (string) ($row[$valueField] ?? ''),
                'description' => $node->dynamic_description_field ? (string) ($row[$node->dynamic_description_field] ?? '') : '',
                'image'       => $node->dynamic_image_field ? ($row[$node->dynamic_image_field] ?? null) : null,
                'subtitle'    => $node->dynamic_subtitle_field ? ($row[$node->dynamic_subtitle_field] ?? null) : null,
            ])
                ->filter(fn($o) => $o['reply_id'] !== '')
                ->take(self::MAX_LIST_ROWS)
                ->values()
                ->all();

            return empty($options) ? null : $options;
        } catch (\Exception $e) {
            Log::error("Dynamic node fetch exception: node={$node->id} " . $e->getMessage());
            return null;
        }
    }

    // ─── Send normalized dynamic options as image(s) + button/list ────────────
    private function sendDynamicOptions(Company $company, string $phone, FlowNode $node, array $options): void
    {
        $hasImages = collect($options)->contains(fn($o) => !empty($o['image']));

        // WhatsApp list/button rows can't carry an image — send one photo per
        // option first (caption = title/subtitle/description), then the picker.
        if ($hasImages) {
            foreach ($options as $i => $opt) {
                if ($i > 0) usleep(400000);
                if (!empty($opt['image'])) {
                    $caption = trim(implode("\n", array_filter([$opt['title'], $opt['subtitle'] ?? null, $opt['description'] ?? null])));
                    $this->sendSingleMedia($company, $phone, 'image', $opt['image'], $caption, null);
                }
            }
            usleep(400000);
        }

        $body = $node->message ?: 'Please choose an option:';

        if (count($options) <= 3) {
            $this->sendButtonFromArray($company, $phone, $body, $options);
        } else {
            $this->sendListFromArray($company, $phone, $body, $options);
        }
    }

    // ─── Default fallback message when something upstream fails ───────────────
    private function sendFallbackMessage(Company $company, string $phone): void
    {
        $this->sendText(
            $company,
            $phone,
            "Sorry, that option isn't available right now — please try again in a moment, or reply *menu* to start over."
        );
    }

    // ─── Send an array of messages sequentially (text/image/video/document/audio/location) ─
    private function sendMultipleMessages(Company $company, string $phone, array $messages): void
    {
        foreach ($messages as $i => $msg) {
            if ($i > 0) {
                usleep(400000); // WhatsApp doesn't guarantee order without a delay
            }

            $type = $msg['type'] ?? 'text';

            match ($type) {
                'text'     => $this->sendText($company, $phone, $msg['content'] ?? $msg['text'] ?? ''),
                'image'    => $this->sendSingleMedia($company, $phone, 'image',    $msg['url'], $msg['caption'] ?? null, null),
                'video'    => $this->sendSingleMedia($company, $phone, 'video',    $msg['url'], $msg['caption'] ?? null, null),
                'document' => $this->sendSingleMedia($company, $phone, 'document', $msg['url'], $msg['caption'] ?? null, $msg['filename'] ?? 'Document.pdf'),
                'audio'    => $this->sendAudio($company, $phone, $msg['url']),
                'location' => $this->dispatch($company, [
                    'messaging_product' => 'whatsapp',
                    'to'                => $phone,
                    'type'              => 'location',
                    'location'          => [
                        'latitude'  => $msg['lat'],
                        'longitude' => $msg['lng'],
                        'name'      => $msg['name']    ?? '',
                        'address'   => $msg['address'] ?? '',
                    ],
                ]),
                default => null,
            };
        }
    }

    // ─── Auto-create lead from flow ───────────────────────────────────────────
    private function autoCreateLead(Company $company, Contact $contact, FlowNode $node): void
    {
        $existing = $this->leadRepository->findByContact($contact->id, $company->id);
        if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
            Log::info("Lead already active for contact {$contact->id} — skipping auto-create");
            return;
        }

        $dto = CreateLeadDTO::fromFlow(
            contactId: $contact->id,
            flowNodeId: $node->id,
            category: $node->lead_category,
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
                            'id'    => mb_substr($c->reply_id, 0, 256),
                            'title' => mb_substr($c->title, 0, 20),
                        ],
                    ])->values()->all(),
                ],
            ],
        ]);
    }

    // ── Button variant for dynamic (array-based) options ──────────────────
    private function sendButtonFromArray(Company $company, string $phone, string $body, array $options): void
    {
        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => [
                    'buttons' => collect($options)->map(fn($o) => [
                        'type'  => 'reply',
                        'reply' => [
                            'id'    => mb_substr($o['reply_id'], 0, 256),
                            'title' => mb_substr($o['title'], 0, 20),
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
                            'id'          => mb_substr($c->reply_id, 0, 200),
                            'title'       => mb_substr($c->title, 0, 24),
                            'description' => mb_substr($c->message ?? '', 0, 72),
                        ])->values()->all(),
                    ]],
                ],
            ],
        ]);
    }

    // ── List variant for dynamic (array-based) options ─────────────────────
    private function sendListFromArray(Company $company, string $phone, string $body, array $options): void
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
                        'rows'  => collect($options)->map(fn($o) => [
                            'id'          => mb_substr($o['reply_id'], 0, 200),
                            'title'       => mb_substr($o['title'], 0, 24),
                            'description' => mb_substr($o['description'] ?? $o['subtitle'] ?? '', 0, 72),
                        ])->values()->all(),
                    ]],
                ],
            ],
        ]);
    }

    // ── Button with optional media header (single node, no multi_messages) ─
    private function sendButtonWithOptionalMedia(Company $company, string $phone, FlowNode $node, $children): void
    {
        $interactive = [
            'type' => 'button',
            'body' => ['text' => $node->message],
            'action' => [
                'buttons' => $children->map(fn($c) => [
                    'type'  => 'reply',
                    'reply' => [
                        'id'    => mb_substr($c->reply_id, 0, 256),
                        'title' => mb_substr($c->title,    0, 20),
                    ],
                ])->values()->all(),
            ],
        ];

        if ($node->media_type && $node->media_url && in_array($node->media_type, ['image', 'video', 'document'])) {
            $interactive['header'] = [
                'type'                         => strtoupper($node->media_type),
                strtolower($node->media_type)  => ['link' => $node->media_url],
            ];
        }

        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]);
    }

    // ── Send image / video / document ───────────────────────────────────────
    private function sendSingleMedia(Company $company, string $phone, string $type, string $url, ?string $caption, ?string $filename): void
    {
        $payload = ['link' => $url];
        if ($caption)  $payload['caption']  = $caption;
        if ($filename) $payload['filename'] = $filename;

        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => $type,
            $type               => $payload,
        ]);
    }

    // ── Send audio ────────────────────────────────────────────────────────
    private function sendAudio(Company $company, string $phone, string $url): void
    {
        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'audio',
            'audio'             => ['link' => $url],
        ]);
    }

    // ── Send location ─────────────────────────────────────────────────────
    private function sendLocation(Company $company, string $phone, FlowNode $node): void
    {
        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'location',
            'location'          => [
                'latitude'  => $node->location_lat,
                'longitude' => $node->location_lng,
                'name'      => $node->location_name    ?? '',
                'address'   => $node->location_address ?? '',
            ],
        ]);
    }

    // ─── POST to Meta Graph API (single dispatch path — no more duplicate) ────
    private function dispatch(Company $company, array $payload): void
    {
        if (!$company->wa_phone_id || !$company->wa_access_token) {
            Log::error("Company {$company->id} has no WA credentials — cannot send message");
            return;
        }

        try {
            $token = $company->wa_access_token;
            try {
                $token = decrypt($token);
            } catch (\Exception) { /* already plain */
            }

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
            'image'       => '[Image]' . (!empty($payload['image']['caption']) ? ': ' . $payload['image']['caption'] : ''),
            'video'       => '[Video]' . (!empty($payload['video']['caption']) ? ': ' . $payload['video']['caption'] : ''),
            'document'    => '[Document]' . (!empty($payload['document']['filename']) ? ': ' . $payload['document']['filename'] : ''),
            'audio'       => '[Audio message]',
            'location'    => '[Location]' . (!empty($payload['location']['name']) ? ': ' . $payload['location']['name'] : ''),
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
