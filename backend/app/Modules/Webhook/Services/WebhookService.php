<?php

namespace App\Modules\Webhook\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\FlowNode;
use App\Models\FlowSession;
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
    public function __construct(
        private readonly LeadRepositoryInterface $leadRepository,
    ) {}

    // ─── Handle inbound message ───────────────────────────────────────────────
    public function handleInbound(Company $company, InboundMessageDTO $dto): void
    {
        // 1. Find or create contact
        $contact = $this->resolveContact($company, $dto);

        // 2. Log inbound message
        MessageLog::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'wa_message_id' => $dto->messageId,
            'direction'  => 'inbound',
            'type'       => $dto->type,
            'phone'      => $dto->phone,
            'content'    => $dto->rawPayload ?? [],
            'status'     => 'delivered',
            'cost'       => 0,
        ]);

        // Update contact last_message_at
        $contact->update(['last_message_at' => now()]);

        // 3. If text message with greeting keywords → send welcome menu
        if ($dto->type === 'text' && $this->isGreeting($dto->text)) {
            $this->sendWelcomeMenu($company, $dto->phone);
            return;
        }

        // 4. If interactive reply → match flow node
        if ($dto->type === 'interactive' && $dto->replyId) {
            $this->handleFlowReply($company, $contact, $dto);
            return;
        }

        // 5. Default: echo acknowledgment
        $this->sendTextMessage($company, $dto->phone, "Thank you for your message! We'll get back to you soon.");
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
        if (in_array($dto->status, ['delivered', 'read'])) {
            $cc = \App\Models\CampaignContact::where('wa_message_id', $dto->waMessageId)->first();
            if ($cc) {
                $cc->update([
                    'status'       => $dto->status,
                    'delivered_at' => $dto->status === 'delivered' ? now() : $cc->delivered_at,
                    'read_at'      => $dto->status === 'read'      ? now() : $cc->read_at,
                ]);

                // Increment campaign counter
                \App\Models\Campaign::where('id', $cc->campaign_id)->increment($dto->status);
            }
        }
    }

    // ─── Match reply_id to flow node → send response → maybe create lead ─────
    private function handleFlowReply(Company $company, Contact $contact, InboundMessageDTO $dto): void
    {
        // Find matching active node
        $node = FlowNode::where('company_id', $company->id)
            ->where('reply_id', $dto->replyId)
            ->where('is_active', true)
            ->first();

        if (!$node) {
            $this->sendTextMessage($company, $dto->phone, "Sorry, that option is not available right now.");
            return;
        }

        // Increment trigger count
        $node->increment('trigger_count');

        // Update or create flow session
        FlowSession::updateOrCreate(
            ['company_id' => $company->id, 'phone' => $dto->phone],
            [
                'contact_id'      => $contact->id,
                'current_node_id' => $node->id,
                'context'         => ['last_reply_id' => $dto->replyId, 'last_title' => $dto->replyTitle],
                'expires_at'      => now()->addHours(24),
            ]
        );

        // Send node message + children as interactive
        $this->sendNodeResponse($company, $dto->phone, $node);

        // Auto-create lead if node has a lead_category
        if ($node->lead_category) {
            $this->autoCreateLead($company, $contact, $node);
        }
    }

    // ─── Send node message with children as buttons/list ─────────────────────
    private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
    {
        $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

        if ($children->isEmpty() || $node->type === 'text') {
            $this->sendTextMessage($company, $phone, $node->message);
            return;
        }

        if ($node->type === 'button' && $children->count() <= 3) {
            $this->sendButtonMessage($company, $phone, $node->message, $children);
        } else {
            $this->sendListMessage($company, $phone, $node->message, $children);
        }
    }

    // ─── Auto-create lead from flow ───────────────────────────────────────────
    private function autoCreateLead(Company $company, Contact $contact, FlowNode $node): void
    {
        // Skip if active lead already exists for this contact
        $existing = $this->leadRepository->findByContact($contact->id, $company->id);
        if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
            return;
        }

        $dto = CreateLeadDTO::fromFlow(
            contactId:  $contact->id,
            flowNodeId: $node->id,
            category:   $node->lead_category,
        );

        try {
            $this->leadRepository->create($company->id, $dto);
            Log::info("Auto-created lead for contact {$contact->id} from flow node {$node->id}");
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

    // ─── WhatsApp API helpers ─────────────────────────────────────────────────
    private function sendTextMessage(Company $company, string $phone, string $text): void
    {
        $this->sendToWhatsApp($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => ['body' => $text],
        ]);
    }

    private function sendButtonMessage(Company $company, string $phone, string $body, $children): void
    {
        $this->sendToWhatsApp($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => [
                    'buttons' => $children->map(fn($c) => [
                        'type'  => 'reply',
                        'reply' => ['id' => $c->reply_id, 'title' => $c->title],
                    ])->values()->all(),
                ],
            ],
        ]);
    }

    private function sendListMessage(Company $company, string $phone, string $body, $children): void
    {
        $this->sendToWhatsApp($company, [
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
                            'id'          => $c->reply_id,
                            'title'       => $c->title,
                            'description' => mb_substr($c->message, 0, 72),
                        ])->values()->all(),
                    ]],
                ],
            ],
        ]);
    }

    private function sendWelcomeMenu(Company $company, string $phone): void
    {
        $roots = FlowNode::where('company_id', $company->id)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($roots->isEmpty()) {
            $this->sendTextMessage($company, $phone, "Welcome! How can we help you today?");
            return;
        }

        // Send first root node
        $this->sendNodeResponse($company, $phone, $roots->first());
    }

    private function sendToWhatsApp(Company $company, array $payload): void
    {
        if (!$company->wa_phone_id || !$company->wa_access_token) return;

        try {
            $response = Http::withToken(decrypt($company->wa_access_token))
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
                    'content'       => $payload,
                    'status'        => 'sent',
                    'cost'          => 0,
                ]);
            } else {
                Log::error('WhatsApp send failed', ['response' => $response->json()]);
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp send exception: ' . $e->getMessage());
        }
    }

    private function isGreeting(?string $text): bool
    {
        if (!$text) return false;
        $greetings = ['hi', 'hello', 'hey', 'start', 'menu', 'hai', 'helo'];
        return in_array(strtolower(trim($text)), $greetings);
    }
}
