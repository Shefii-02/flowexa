<?php
namespace App\Modules\Webhook\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Models\MessageBlacklist;
use App\Models\MessageLog;
use App\Models\SurveyForm;
use App\Models\SurveyFormResponse;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Modules\Conversation\Events\WaMessageReceived;
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
        'stop', 'unsubscribe', 'optout', 'opt out', 'quit', 'cancel', 'remove', 'end', 'block', 'no more',
    ];

    // ── Re-subscribe keywords ──────────────────────────────────────────────
    private const OPT_IN_KEYWORDS = [
        'start', 'subscribe', 'yes', 'optin', 'opt in', 'resume', 'restart', 'begin',
    ];

    // ── Greeting keywords → send welcome menu ──────────────────────────────
    private const GREETING_KEYWORDS = [
        'hi', 'hello', 'hey', 'start', 'main menu', 'menu', 'hai', 'helo', 'hii', 'hola', 'namaste', 'vanakkam', 'ഹലോ',
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

        // 3. Log inbound message with full content (existing per-message audit log)
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

        // 3b. Mirror into the realtime conversation inbox (wa_conversations/wa_messages) —
        // separate from MessageLog above, which is the flat audit trail. This is what
        // powers the shared inbox UI (counsellors seeing/replying to the same thread).
        $this->logInboundToInbox($company, $contact, $dto);

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
                $this->sendText($company, $dto->phone, "Welcome back! 👋 You have been resubscribed successfully. ✅");
                // Continue — send welcome menu below
            }
        }

        // 5. Check opted-in status — skip opted-out contacts
        if (!$contact->fresh()->opted_in) {
            Log::info("Contact {$contact->id} is opted out — skipping flow routing");
            return;
        }

        // 6a. Native WhatsApp Flow submission — customer tapped Submit on a bottom-sheet
        // form. This arrives as ONE message with all answers, not a stepped conversation,
        // so it's checked before the sequential-survey-answer path below.
        if ($dto->type === 'interactive' && ($dto->interactiveType ?? null) === 'nfm_reply') {
            if ($this->handleNfmReply($company, $contact, $dto)) return;
        }

        // 6b. Active survey capture (sequential text-message mode) — checked BEFORE
        // keyword/greeting/menu matching, so a customer's answer (which might literally
        // be "hi", "1", "yes", etc.) is captured as survey data instead of being
        // mistaken for a bot command.
        if ($dto->type === 'text' && $this->handleSurveyAnswerIfActive($company, $contact, $dto)) {
            return;
        }

        // 7. Resolve active flow builder
        [$builder, $triggerReason] = $this->resolveFlowBuilderWithReason($company, $dto->text ?? '');

        // 8. Check active flow session (mid-conversation)
        $session = FlowSession::where('company_id', $company->id)
            ->where('phone', $dto->phone)
            ->where('expires_at', '>', now())
            ->first();

        // 9. Interactive reply → match flow node
        if ($dto->type === 'interactive' && $dto->replyId) {
            $this->handleFlowReply($company, $contact, $dto, $builder?->id);
            return;
        }

        // 10. If a KEYWORD builder matched → always send its welcome menu
        if ($triggerReason === 'keyword' && $builder) {
            Log::info("Keyword triggered builder {$builder->id} '{$builder->name}' — sending its welcome menu");
            $session?->delete();
            $this->sendWelcomeMenu($company, $dto->phone, $builder->id);
            return;
        }

        // 11. Text greeting OR new conversation → send welcome menu
        if ($dto->type === 'text' && $this->isGreeting($dto->text)) {
            $session?->delete();
            $this->sendWelcomeMenu($company, $dto->phone, $builder?->id);
            return;
        }

        // 12. Mid-session text → try to match current node's children
        if ($session && $dto->type === 'text') {
            $matched = $this->matchTextToNode($company, $contact, $dto, $session, $builder?->id);
            if ($matched) return;
        }

        // 13. Default fallback response
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

        // Mirror the status onto the inbox message row too, so the shared inbox shows
        // sent/delivered/read ticks the same way MessageLog does for the audit trail.
        $inboxMessage = WaMessage::where('wa_message_id', $dto->waMessageId)->first();
        if ($inboxMessage) {
            // Defensive: only reference errorMessage if your StatusUpdateDTO actually
            // carries one — adjust the property name here to match your DTO if it differs.
            $failureReason = ($dto->status === 'failed' && property_exists($dto, 'errorMessage'))
                ? $dto->errorMessage
                : null;

            $inboxMessage->update([
                'status'            => $dto->status,
                'failure_reason'    => $failureReason,
                'status_updated_at' => now(),
            ]);
            $this->broadcastInboxMessage($inboxMessage);
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

    // ════════════════════════════════════════════════════════════════════
    // SURVEY FORMS — node type 'survey': asks each configured question as a
    // plain text message, one at a time, and stores the customer's replies.
    // ════════════════════════════════════════════════════════════════════

    // Begin a survey: create the response row, link it to the session, ask question 1.
    private function startSurvey(Company $company, Contact $contact, string $phone, FlowNode $node): void
    {
        Log::info("Survey Started");

        $form = SurveyForm::where('id', $node->survey_form_id)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();
        Log::info("Form Getted");
        Log::info($form);
        if (!$form || empty($form->fields)) {
            Log::warning("Survey node {$node->id} has no usable survey_form — falling back to fallback message", ['company' => $company->id]);
            $this->sendFallbackMessage($company, $phone);
            return;
        }

        $response = SurveyFormResponse::create([
            'survey_form_id'      => $form->id,
            'company_id'          => $company->id,
            'contact_id'          => $contact->id,
            'phone'               => $phone,
            'flow_node_id'        => $node->id,
            'answers'             => [],
            'status'              => 'in_progress',
            'current_field_index' => 0,
        ]);

        FlowSession::updateOrCreate(
            ['company_id' => $company->id, 'phone' => $phone],
            [
                'contact_id'                 => $contact->id,
                'current_node_id'            => $node->id,
                'flow_builder_id'            => $node->flow_builder_id,
                'active_survey_response_id'  => $response->id,
                'context'                    => ['survey_form_id' => $form->id],
                'expires_at'                 => now()->addHours(24),
            ]
        );

        // Native bottom-sheet Flow (published on Meta) — one message, one screen with
        // all fields, single nfm_reply on submit. Preferred whenever it's available.
        if ($form->isNativeFlowReady()) {
            Log::info("Botton Form Getted");

            $this->sendSurveyFlowMessage($company, $phone, $form);
                    Log::info("Botton Form Done");
            return;
        }

        // Fallback: sequential text-message survey (no Flow published for this form yet)
        if ($form->description) {
            $this->sendText($company, $phone, $form->description);
            usleep(150000);
        }

        $this->sendSurveyQuestion($company, $phone, $form, 0);
    }

    // Ask one question. Choice questions become a button/list picker so answers stay
    // clean; text/number questions are asked as plain text and the reply is captured raw.
    private function sendSurveyQuestion(Company $company, string $phone, SurveyForm $form, int $index): void
    {
        $field = $form->fields[$index] ?? null;
        if (!$field) return;

        $questionText = $field['question_text'];

        if (($field['type'] ?? 'text') === 'choice' && !empty($field['options'])) {
            $options = collect($field['options'])->values()->map(fn ($opt, $i) => [
                'title'    => (string) $opt,
                'reply_id' => 'survey_opt_' . $i,
            ])->all();

            if (count($options) <= 3) {
                $this->sendButtonFromArray($company, $phone, $questionText, $options);
            } else {
                $this->sendListFromArray($company, $phone, $questionText, $options);
            }
            return;
        }

        $this->sendText($company, $phone, $questionText);
    }

    // Send the survey as a native WhatsApp Flow — one interactive message that opens
    // a bottom-sheet form with all fields on one screen. No data-exchange endpoint
    // needed here since the form is static (navigate mode, terminal screen).
   private function sendSurveyFlowMessage(
    Company $company,
    string $phone,
    SurveyForm $form
): void {
    Log::info("Bottom sheet opening");

    $this->dispatch($company, [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'interactive',

        'interactive' => [
            'type' => 'flow',

            'body' => [
                'text' => $form->description
                    ?: "Please fill out this quick form: {$form->name}",
            ],

            'action' => [
                'name' => 'flow',

                'parameters' => [
                    'flow_message_version' => '3',

                    'flow_token' =>
                        'survey_' . $form->id . '_' . now()->timestamp,

                    'flow_id' => $form->flow_id,

                    'flow_cta' => 'Start',

                    'flow_action' => 'navigate',

                    'flow_action_payload' => [
                        'screen' => 'SURVEY',
                        // 'data'   => new \stdClass(),
                    ],
                ],
            ],
        ],
    ]);

    Log::info("Bottom sheet done");
}

    // Captures a native Flow submission (one message, all answers at once) instead of
    // stepping through fields one by one. Returns true if this was a survey submission
    // (caller should stop further routing); false if there's no matching survey in
    // progress (e.g. a Flow message unrelated to a survey — safe to fall through).
    private function handleNfmReply(Company $company, Contact $contact, InboundMessageDTO $dto): bool
    {
        $session = FlowSession::where('company_id', $company->id)
            ->where('phone', $dto->phone)
            ->where('expires_at', '>', now())
            ->whereNotNull('active_survey_response_id')
            ->first();

        if (!$session) return false;

        $response = SurveyFormResponse::where('id', $session->active_survey_response_id)
            ->where('status', 'in_progress')
            ->first();

        if (!$response) {
            $session->update(['active_survey_response_id' => null]);
            return false;
        }

        $form = SurveyForm::find($response->survey_form_id);
        if (!$form) {
            $response->update(['status' => 'abandoned']);
            $session->update(['active_survey_response_id' => null]);
            return false;
        }

        // The raw nfm_reply payload's response_json is a JSON-encoded string containing
        // {field_key: answer, ...} for every field on the screen — decode it directly
        // rather than reconstructing it field-by-field like the sequential path does.
        $raw = $dto->rawPayload['interactive']['nfm_reply']['response_json'] ?? null;
        $answers = $raw ? (json_decode($raw, true) ?: []) : [];

        if (empty($answers)) {
            Log::warning('[survey-flow] nfm_reply had no parseable response_json', [
                'company' => $company->id,
                'form'    => $form->id,
                'raw'     => $dto->rawPayload['interactive']['nfm_reply'] ?? null,
            ]);
        }

        $response->update([
            'answers'             => $answers,
            'current_field_index' => count($form->fields ?? []),
        ]);

        $this->finishSurvey($company, $contact, $response, $form);
        $session->update(['active_survey_response_id' => null]);

        return true;
    }

    // Called from handleInbound BEFORE any keyword/menu routing (sequential text-message
    // mode only — native Flow submissions are handled by handleNfmReply above instead).
    // Returns true if this message was consumed as a survey answer.
    private function handleSurveyAnswerIfActive(Company $company, Contact $contact, InboundMessageDTO $dto): bool
    {
        $session = FlowSession::where('company_id', $company->id)
            ->where('phone', $dto->phone)
            ->where('expires_at', '>', now())
            ->whereNotNull('active_survey_response_id')
            ->first();

        if (!$session) return false;

        $response = SurveyFormResponse::where('id', $session->active_survey_response_id)
            ->where('status', 'in_progress')
            ->first();

        if (!$response) {
            // Stale link — clear it and fall through to normal routing
            $session->update(['active_survey_response_id' => null]);
            return false;
        }

        $form = SurveyForm::find($response->survey_form_id);
        if (!$form || empty($form->fields)) {
            $response->update(['status' => 'abandoned']);
            $session->update(['active_survey_response_id' => null]);
            return false;
        }

        $index = $response->current_field_index;
        $field = $form->fields[$index] ?? null;
        if (!$field) {
            // Index out of range somehow — treat as complete
            $this->finishSurvey($company, $contact, $response, $form);
            $session->update(['active_survey_response_id' => null]);
            return true;
        }

        // Interpret the answer. For a 'choice' field answered via button/list, the reply
        // arrives as type=interactive with replyId 'survey_opt_N' — but we also accept a
        // typed number/text match, since customers sometimes type instead of tapping.
        $rawAnswer = trim($dto->text ?? '');
        $answer = $rawAnswer;

        if (($field['type'] ?? 'text') === 'choice' && !empty($field['options'])) {
            $options = array_values($field['options']);
            if (preg_match('/^survey_opt_(\d+)$/', $dto->replyId ?? '', $m) && isset($options[(int) $m[1]])) {
                $answer = $options[(int) $m[1]];
            } elseif (ctype_digit($rawAnswer) && isset($options[(int) $rawAnswer - 1])) {
                $answer = $options[(int) $rawAnswer - 1]; // "2" → second option, 1-indexed for humans
            } elseif (!in_array($rawAnswer, $options, true)) {
                // Didn't match any option — re-ask instead of silently accepting garbage
                $this->sendText($company, $dto->phone, "Please choose one of the listed options.");
                $this->sendSurveyQuestion($company, $dto->phone, $form, $index);
                return true;
            }
        }

        if (($field['type'] ?? 'text') === 'number' && !is_numeric($rawAnswer)) {
            $this->sendText($company, $dto->phone, "Please reply with a number for: {$field['question_text']}");
            return true;
        }

        if (!empty($field['required']) && $rawAnswer === '') {
            $this->sendText($company, $dto->phone, "This question needs an answer: {$field['question_text']}");
            return true;
        }

        $answers = $response->answers ?? [];
        $answers[$field['key']] = $answer;
        $nextIndex = $index + 1;

        $response->update(['answers' => $answers, 'current_field_index' => $nextIndex]);

        if ($nextIndex >= count($form->fields)) {
            $this->finishSurvey($company, $contact, $response, $form);
            $session->update(['active_survey_response_id' => null]);
        } else {
            $this->sendSurveyQuestion($company, $dto->phone, $form, $nextIndex);
        }

        return true;
    }

    private function finishSurvey(Company $company, Contact $contact, SurveyFormResponse $response, SurveyForm $form): void
    {
        $response->update(['status' => 'completed', 'completed_at' => now()]);

        $this->sendText($company, $response->phone, "Thanks! Your responses have been recorded. ✅");

        // Auto-create a lead if the triggering node had a lead_category set — mirrors
        // the existing autoCreateLead() behavior for ordinary flow nodes.
        if ($response->flow_node_id) {
            $node = FlowNode::find($response->flow_node_id);
            if ($node && $node->lead_category) {
                $this->autoCreateLead($company, $contact, $node);
            }
        }

        Log::info("Survey completed: form={$form->id} response={$response->id} phone={$response->phone}");
    }

    // ════════════════════════════════════════════════════════════════════
    // TEMPLATE NODES — node type 'template': sends an approved WA template
    // as a flow step (e.g. a menu option that triggers a formal notification).
    // ════════════════════════════════════════════════════════════════════
    private function sendTemplateNode(Company $company, string $phone, FlowNode $node): void
    {
        $template = WaTemplate::where('id', $node->wa_template_id)
            ->where('company_id', $company->id)
            ->where('status', 'approved')
            ->first();

        if (!$template || !$template->wa_template_id) {
            Log::warning("Template node {$node->id} has no approved template linked — falling back", ['company' => $company->id]);
            $this->sendFallbackMessage($company, $phone);
            return;
        }

        // NOTE: this sends the template as-authored, with no runtime variable
        // substitution — flow nodes don't currently have a per-customer variable
        // source. If the template has {{1}}, {{2}} body variables, wire a mapping
        // here (e.g. from contact fields) before going to production with those.
        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'     => $template->name,
                'language' => ['code' => $template->language],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONVERSATION INBOX — mirrors inbound/outbound messages into
    // wa_conversations/wa_messages so the shared realtime inbox stays in sync
    // with everything the bot (and agents) send/receive.
    // ═══════════════════════════════════════════════════════════════════════
    private function logInboundToInbox(Company $company, Contact $contact, InboundMessageDTO $dto): void
    {
        try {
            $conversation = WaConversation::firstOrCreate(
                ['company_id' => $company->id, 'phone' => $dto->phone],
                ['contact_id' => $contact->id, 'contact_name' => $contact->name ?? null, 'status' => 'open']
            );

            $conversation->update([
                'status'          => 'open',
                'last_message_at' => now(),
                'unread_count'    => $conversation->unread_count + 1,
            ]);

            $message = WaMessage::create([
                'conversation_id' => $conversation->id,
                'company_id'      => $company->id,
                'direction'       => 'inbound',
                'sender_type'     => 'customer',
                'wa_message_id'   => $dto->messageId,
                'type'            => $dto->type,
                'content'         => ['body' => $this->extractContent($dto)],
                'status'          => 'delivered',
                'status_updated_at' => now(),
            ]);

            $this->broadcastInboxMessage($message->fresh(['conversation']));
        } catch (\Throwable $e) {
            // Inbox logging is additive — never let it break actual bot processing
            Log::warning('[inbox] failed to log inbound message: ' . $e->getMessage(), ['company' => $company->id]);
        }
    }

    private function logOutboundToInbox(Company $company, array $payload, ?string $waId, string $status, ?string $failureReason = null): void
    {
        try {
            $conversation = WaConversation::firstOrCreate(
                ['company_id' => $company->id, 'phone' => $payload['to']],
                ['status' => 'open']
            );
            $conversation->update(['last_message_at' => now()]);

            $message = WaMessage::create([
                'conversation_id' => $conversation->id,
                'company_id'      => $company->id,
                'direction'       => 'outbound',
                'sender_type'     => 'bot',
                'wa_message_id'   => $waId,
                'type'            => $payload['type'],
                'content'         => ['body' => $this->extractOutboundContent($payload)],
                'status'          => $status,
                'failure_reason'  => $failureReason,
                'status_updated_at' => now(),
            ]);

            $this->broadcastInboxMessage($message->fresh(['conversation']));
        } catch (\Throwable $e) {
            Log::warning('[inbox] failed to log outbound message: ' . $e->getMessage(), ['company' => $company->id]);
        }
    }

    private function broadcastInboxMessage(WaMessage $message): void
    {
        try {
            if (class_exists(WaMessageReceived::class)) {
                broadcast(new WaMessageReceived($message));
            }
        } catch (\Throwable $e) {
            Log::warning('[inbox] broadcast failed: ' . $e->getMessage());
        }
    }

    // ─── Resolve which flow builder to use ────────────────────────────────────
    // Priority: keyword → season (date range) → default active
    private function resolveFlowBuilderWithReason(Company $company, string $text): array
    {
        $keyword = strtolower(trim($text));
        $now     = now();

        $keywordBuilders = FlowBuilder::where('company_id', $company->id)
            ->where('trigger_type', 'keyword')
            ->where('is_active', true)
            ->get();

        foreach ($keywordBuilders as $builder) {
            $keywords = $builder->trigger_keywords ?? [];
            if (is_string($keywords)) {
                $keywords = json_decode($keywords, true) ?? [];
            }

            $lowerKeywords = array_map('strtolower', array_map('trim', $keywords));

            if (in_array($keyword, $lowerKeywords, true)) {
                Log::info("Keyword flow triggered: builder={$builder->id} name='{$builder->name}' keyword='{$keyword}'");
                return [$builder, 'keyword'];
            }
        }

        $seasonBuilder = FlowBuilder::where('company_id', $company->id)
            ->where('trigger_type', 'season')
            ->where('is_active', true)
            ->where('active_from',  '<=', $now)
            ->where('active_until', '>=', $now)
            ->orderByDesc('active_from')
            ->first();

        if ($seasonBuilder) {
            Log::info("Season flow active: builder={$seasonBuilder->id} name='{$seasonBuilder->name}'");
            return [$seasonBuilder, 'season'];
        }

        $default = FlowBuilder::where('company_id', $company->id)
            ->where('trigger_type', 'default')
            ->where('is_active', true)
            ->first();

        if (!$default) {
            Log::warning("No active flow builder found for company={$company->id}");
            return [null, null];
        }

        return [$default, 'default'];
    }

    // ─── Match reply_id to flow node → send response → maybe create lead ─────
    private function handleFlowReply(
        Company $company,
        Contact $contact,
        InboundMessageDTO $dto,
        ?int $builderId
    ): void {
        // Log::info("Handling flow reply: company={$company->id} contact={$contact->id} reply_id={$dto->replyId} builder_id={$builderId}");
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

        Log::info("Flow node triggered: node={$node->id} reply_id={$dto->replyId} company={$company->id} type={$node->type}");

         // ── REDIRECT support ──────────────────────────────────────────────
        // If node has redirect_to_reply_id, jump to that node instead.
        // Used for "Back" buttons and "Main Menu" buttons.
        if ($node->redirect_to_reply_id) {
            $targetQuery = FlowNode::where('company_id', $company->id)
                ->where('reply_id', $node->redirect_to_reply_id)
                ->where('is_active', true);

            if ($builderId) {
                $targetQuery->where('flow_builder_id', $builderId);
            }

            $target = $targetQuery->first();

            if ($target) {
                Log::info("Redirect: {$node->reply_id} → {$target->reply_id}");

                // Update session to the target node
                FlowSession::updateOrCreate(
                    ['company_id' => $company->id, 'phone' => $dto->phone],
                    [
                        'contact_id'      => $contact->id,
                        'current_node_id' => $target->id,
                        'flow_builder_id' => $target->flow_builder_id,
                        'expires_at'      => now()->addHours(24),
                    ]
                );

                $target->increment('trigger_count');
                $this->sendNodeResponse($company, $dto->phone, $target);

                // Auto-lead if target has category
                if ($target->lead_category) {
                    $this->autoCreateLead($company, $contact, $target);
                }
                return;
            }

            // redirect_to_reply_id is 'WELCOME' → send welcome menu
            if (strtoupper($node->redirect_to_reply_id) === 'WELCOME') {
                FlowSession::where('company_id', $company->id)
                    ->where('phone', $dto->phone)
                    ->delete();
                $this->sendWelcomeMenu($company, $dto->phone, $builderId);
                return;
            }

            Log::warning("Redirect target '{$node->redirect_to_reply_id}' not found — sending node response");
        }
        // Normal flow — no redirect
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

        // ── Special node types short-circuit the normal response-building path ──
        if ($node->type === 'survey' && $node->survey_form_id) {
            Log::info("Survey Starting");
            $this->startSurvey($company, $contact, $dto->phone, $node);
            return;
        }

        if ($node->type === 'template' && $node->wa_template_id) {
            $this->sendTemplateNode($company, $dto->phone, $node);
            if ($node->lead_category) {
                $this->autoCreateLead($company, $contact, $node);
            }
            return;
        }

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
    // private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
    // {
    //     if ($node->is_dynamic && $node->dynamic_api_url) {
    //         if ($node->message) {
    //             $this->sendText($company, $phone, $node->message);
    //             usleep(100000);
    //         }

    //         $options = $this->resolveDynamicOptions($node);

    //         if ($options === null) {
    //             $this->sendFallbackMessage($company, $phone);
    //             return;
    //         }

    //         $this->sendDynamicOptions($company, $phone, $node, $options);
    //         return;
    //     }

    //     $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

    //     if ($node->hasMultipleMessages()) {
    //         $this->sendMultipleMessages($company, $phone, $node->multi_messages);

    //         if ($children->isNotEmpty()) {
    //             usleep(100000);
    //             if ($node->type === 'button' && $children->count() <= 3) {
    //                 $this->sendButton($company, $phone, '👇 Please select an option:', $children);
    //             } else {
    //                 $this->sendList($company, $phone, '👇 Please select an option:', $children);
    //             }
    //         }

    //         if ($node->message) {
    //             usleep(100000);
    //             $this->sendText($company, $phone, $node->message);
    //         }

    //         return;
    //     }

    //     if ($node->media_type === 'audio' && $node->media_url) {
    //         $this->sendAudio($company, $phone, $node->media_url);
    //         if ($node->message) {
    //             usleep(100000);
    //             $this->sendText($company, $phone, $node->message);
    //         }
    //         return;
    //     }

    //     if ($node->media_type === 'location' && $node->location_lat) {
    //         $this->sendLocation($company, $phone, $node);
    //         return;
    //     }

    //     if ($children->isEmpty()) {
    //         if ($node->media_type && $node->media_url) {
    //             $this->sendSingleMedia($company, $phone, $node->media_type, $node->media_url, $node->media_caption, $node->media_filename);
    //         } else {
    //             $this->sendText($company, $phone, $node->message ?: 'Thanks for your reply!');
    //         }
    //         return;
    //     }

    //     if ($node->type === 'button' && $children->count() <= 3) {
    //         $this->sendButtonWithOptionalMedia($company, $phone, $node, $children);
    //     } else {
    //         $this->sendList($company, $phone, $node->message, $children);
    //     }
    // }

    private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
    {
        // ── Dynamic node ──────────────────────────────────────────────────
        if ($node->is_dynamic && $node->dynamic_api_url) {
            if ($node->message) {
                $this->sendText($company, $phone, $node->message);
                usleep(300000);
            }
            $options = $this->resolveDynamicOptions($node);
            if ($options === null) { $this->sendFallbackMessage($company, $phone); return; }
            $this->sendDynamicOptions($company, $phone, $node, $options);
            return;
        }

        $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

        // ── Multi-message ─────────────────────────────────────────────────
        if ($node->hasMultipleMessages()) {
            $this->sendMultipleMessages($company, $phone, $node->multi_messages);
            if ($children->isNotEmpty()) {
                usleep(500000);
                if ($node->type === 'button' && $children->count() <= 3) {
                    $this->sendButton($company, $phone, '👇 Please select an option:', $children);
                } else {
                    $this->sendList($company, $phone, '👇 Please select an option:', $children);
                }
            } elseif (!$node->is_dead_end) {
                // Has multi-messages but no children and NOT marked as terminal
                // → treat as accidental dead end, send welcome menu again
                $this->sendDeadEndRecovery($company, $phone);
            }
            return;
        }

        // ── Single rich media (no children) ──────────────────────────────
        if ($children->isEmpty()) {
            // Send the message or media
            if ($node->media_type === 'audio' && $node->media_url) {
                $this->sendAudio($company, $phone, $node->media_url);
                if ($node->message) { usleep(300000); $this->sendText($company, $phone, $node->message); }
            } elseif ($node->media_type === 'location' && $node->location_lat) {
                $this->sendLocation($company, $phone, $node);
            } elseif ($node->media_type && $node->media_url) {
                $this->sendSingleMedia($company, $phone, $node->media_type, $node->media_url, $node->media_caption, $node->media_filename);
                if ($node->message) { usleep(300000); $this->sendText($company, $phone, $node->message); }
            } else {
                $this->sendText($company, $phone, $node->message ?: '✅ Done!');
            }

            // ── Dead end recovery ─────────────────────────────────────────
            // If node type expects children (list/button) but has none AND is not
            // explicitly marked as a terminal node → customer is stuck → recover.
            if (!$node->is_dead_end && in_array($node->type, ['list', 'button'])) {
                usleep(600000);
                $this->sendDeadEndRecovery($company, $phone);
            }
            return;
        }

        // ── Normal button / list with children ────────────────────────────
        if ($node->type === 'button' && $children->count() <= 3) {
            $this->sendButtonWithOptionalMedia($company, $phone, $node, $children);
        } else {
            $this->sendList($company, $phone, $node->message, $children);
        }
    }

    // ── Dead end recovery — sent when customer reaches a node with no options ──
    // Sends a support message + re-sends the root welcome menu after a delay.
      private function sendDeadEndRecovery(Company $company, string $phone): void
    {
        $this->sendText(
            $company,
            $phone,
            "🙏 We're sorry if that was a dead end!\n\n" .
            "For further assistance please contact our support team or reply *menu* to start over.\n\n" .
            "📞 *Support:* " . ($company->support_phone ?? 'Contact us anytime') . "\n" .
            "📧 *Email:* "   . ($company->support_email ?? 'support@company.com')
        );

        // Re-send welcome menu after 2 seconds so customer can restart
        usleep(2000000);
        $this->sendWelcomeMenu($company, $phone, null);

        Log::warning("Dead end hit: company={$company->id} phone={$phone}");
    }

    // private function sendDeadEndRecovery(Company $company, string $phone): void
    // {
    //     $this->sendText(
    //         $company,
    //         $phone,
    //         "🙏 We're sorry if that was a dead end!\n\n" .
    //         "For further assistance please contact our support team or reply *menu* to start over.\n\n" .
    //         "📞 *Support:* " . ($company->support_phone ?? 'Contact us anytime') . "\n" .
    //         "📧 *Email:* "   . ($company->support_email ?? 'support@company.com')
    //     );

    //     // Re-send welcome menu after 2 seconds so customer can restart
    //     usleep(2000000);
    //     $this->sendWelcomeMenu($company, $phone, null);

    //     Log::warning("Dead end hit: company={$company->id} phone={$phone}");
    // }

    // ─── Fetch + normalize options for a dynamic node ──────────────────────────
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

    // ─── Send an array of messages sequentially ────────────────────────────────
    private function sendMultipleMessages(Company $company, string $phone, array $messages): void
    {
        foreach ($messages as $i => $msg) {
            if ($i > 0) usleep(200000);

            $type = $msg['type'] ?? 'text';

            match ($type) {
                'text'     => $this->sendText($company, $phone, $msg['content'] ?? $msg['text'] ?? ''),
                'image'    => $this->sendSingleMedia($company, $phone, 'image',    $msg['url'], $msg['caption'] ?? null, null, $msg['mime_type'] ?? null),
                'video'    => $this->sendSingleMedia($company, $phone, 'video',    $msg['url'], $msg['caption'] ?? null, null, $msg['mime_type'] ?? null),
                'document' => $this->sendSingleMedia($company, $phone, 'document', $msg['url'], $msg['caption'] ?? null, $msg['filename'] ?? 'Document.pdf', $msg['mime_type'] ?? null),
                'audio'    => $this->sendAudio($company, $phone, $msg['url'], $msg['mime_type'] ?? null),
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
        $category  = $node->lead_category;
        $existing = $this->leadRepository->findByContact($contact->id, $company->id, $category);
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
            $mediaId = $this->uploadMediaToMeta($company, $node->media_url, $this->guessMimeType($node->media_type, $node->media_url));
            $header  = ['type' => strtoupper($node->media_type)];
            $header[strtolower($node->media_type)] = $mediaId ? ['id' => $mediaId] : ['link' => $node->media_url];
            $interactive['header'] = $header;
        }

        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ]);
    }

    private function sendSingleMedia(Company $company, string $phone, string $type, string $url, ?string $caption, ?string $filename, ?string $mimeType = null): void
    {
        $mimeType = $mimeType ?: $this->guessMimeType($type, $url);
        $mediaId  = $this->uploadMediaToMeta($company, $url, $mimeType);

        $payload = $mediaId ? ['id' => $mediaId] : ['link' => $url];
        if ($caption)  $payload['caption']  = $caption;
        if ($filename) $payload['filename'] = $filename;

        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => $type,
            $type               => $payload,
        ]);
    }

    private function sendAudio(Company $company, string $phone, string $url, ?string $mimeType = null): void
    {
        $mimeType = $mimeType ?: 'audio/mpeg';
        $mediaId  = $this->uploadMediaToMeta($company, $url, $mimeType);

        $this->dispatch($company, [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'audio',
            'audio'             => $mediaId ? ['id' => $mediaId] : ['link' => $url],
        ]);
    }

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

    private function uploadMediaToMeta(Company $company, string $url, string $mimeType): ?string
    {
        if (!$company->wa_phone_id || !$company->decrypt_wa_access_token) return null;

        try {
            $token = $company->decrypt_wa_access_token;
            try { $token = decrypt($token); } catch (\Exception) { /* already plain */ }

            $fileResponse = Http::timeout(15)->get($url);
            if (!$fileResponse->successful()) {
                Log::error("uploadMediaToMeta: could not fetch source file {$url} — status={$fileResponse->status()}");
                return null;
            }

            $response = Http::withToken($token)
                ->timeout(20)
                ->attach('file', $fileResponse->body(), basename(parse_url($url, PHP_URL_PATH) ?: 'file'), ['Content-Type' => $mimeType])
                ->post("https://graph.facebook.com/v21.0/{$company->wa_phone_id}/media", [
                    'messaging_product' => 'whatsapp',
                    'type'              => $mimeType,
                ]);

            if ($response->successful()) {
                return $response->json('id');
            }

            Log::error("uploadMediaToMeta: Meta upload failed for {$url} — " . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error("uploadMediaToMeta exception for {$url}: " . $e->getMessage());
            return null;
        }
    }

    private function guessMimeType(string $type, string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return match (true) {
            $type === 'image' && $ext === 'png'          => 'image/png',
            $type === 'image'                            => 'image/jpeg',
            $type === 'video'                            => 'video/mp4',
            $type === 'audio' && $ext === 'ogg'           => 'audio/ogg',
            $type === 'audio'                             => 'audio/mpeg',
            $type === 'document' && $ext === 'pdf'        => 'application/pdf',
            $type === 'document' && $ext === 'doc'        => 'application/msword',
            $type === 'document' && $ext === 'docx'       => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default                                       => 'application/octet-stream',
        };
    }

    // ─── POST to Meta Graph API (single dispatch path) ─────────────────────────
    private function dispatch(Company $company, array $payload): void
    {
        if (!$company->wa_phone_id || !$company->decrypt_wa_access_token) {
            Log::error("Company {$company->id} has no WA credentials — cannot send message");
            return;
        }

        try {
            $token = $company->decrypt_wa_access_token;
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

                $this->logOutboundToInbox($company, $payload, $waId, 'sent');

                Log::info("WA message sent: waId={$waId} to={$payload['to']} company={$company->id}");
            } else {
                $error = $response->json('error.message') ?? 'Unknown error';
                $code  = $response->json('error.code');
                Log::error("WA send failed: [{$code}] {$error}", [
                    'company'  => $company->id,
                    'to'       => $payload['to'],
                    'type'     => $payload['type'],
                    'response' => $response->json(),
                ]);

                MessageLog::create([
                    'company_id' => $company->id,
                    'direction'  => 'outbound',
                    'type'       => $payload['type'],
                    'phone'      => $payload['to'],
                    'content'    => $this->extractOutboundContent($payload) . " [FAILED: {$error}]",
                    'status'     => 'failed',
                    'cost'       => 0,
                ]);

                $this->logOutboundToInbox($company, $payload, null, 'failed', $error);
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
            'template'    => '[Template] ' . ($payload['template']['name'] ?? ''),
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
//     // ── STOP / unsubscribe keywords ────────────────────────────────────────
//     private const OPT_OUT_KEYWORDS = [
//         'stop',
//         'unsubscribe',
//         'optout',
//         'opt out',
//         'quit',
//         'cancel',
//         'remove',
//         'end',
//         'block',
//         'no more',
//     ];

//     // ── Re-subscribe keywords ──────────────────────────────────────────────
//     private const OPT_IN_KEYWORDS = [
//         'start',
//         'subscribe',
//         'yes',
//         'optin',
//         'opt in',
//         'resume',
//         'restart',
//         'begin',
//     ];

//     // ── Greeting keywords → send welcome menu ──────────────────────────────
//     private const GREETING_KEYWORDS = [
//         'hi',
//         'hello',
//         'hey',
//         'start',
//         'menu',
//         'hai',
//         'helo',
//         'hii',
//         'hola',
//         'namaste',
//         'vanakkam',
//         'ഹലോ',
//     ];

//     // Options row cap enforced by WhatsApp for interactive lists
//     private const MAX_LIST_ROWS = 10;

//     public function __construct(
//         private readonly LeadRepositoryInterface $leadRepository,
//     ) {}

//     // ─── Handle inbound message ───────────────────────────────────────────────
//     public function handleInbound(Company $company, InboundMessageDTO $dto): void
//     {
//         // 1. Blacklist check — silent ignore, no reply, no lead
//         $isBlacklisted = MessageBlacklist::where('company_id', $company->id)
//             ->where('phone', $dto->phone)
//             ->exists();

//         if ($isBlacklisted) {
//             Log::info("Blacklisted number {$dto->phone} ignored for company {$company->id}");
//             return;
//         }

//         // 2. Find or create contact
//         $contact = $this->resolveContact($company, $dto);

//         // 3. Log inbound message with full content
//         MessageLog::create([
//             'company_id'    => $company->id,
//             'contact_id'    => $contact->id,
//             'wa_message_id' => $dto->messageId,
//             'direction'     => 'inbound',
//             'type'          => $dto->type,
//             'phone'         => $dto->phone,
//             'content'       => $this->extractContent($dto),
//             'status'        => 'received',
//             'cost'          => 0,
//         ]);

//         $contact->update(['last_message_at' => now()]);

//         // 4. STOP / opt-out handling (before any flow routing)
//         if ($dto->type === 'text') {
//             $msgText = strtolower(trim($dto->text ?? ''));

//             if (in_array($msgText, self::OPT_OUT_KEYWORDS)) {
//                 $contact->update(['opted_in' => false, 'opted_out_at' => now()]);
//                 $this->sendText(
//                     $company,
//                     $dto->phone,
//                     "You have been unsubscribed. ✅\n\nYou will no longer receive messages from us.\n\nReply *START* anytime to resubscribe."
//                 );
//                 Log::info("Contact {$contact->id} opted out via keyword: {$msgText}");
//                 return;
//             }

//             if (in_array($msgText, self::OPT_IN_KEYWORDS) && !$contact->opted_in) {
//                 $contact->update(['opted_in' => true, 'opted_out_at' => null]);
//                 $this->sendText(
//                     $company,
//                     $dto->phone,
//                     "Welcome back! 👋 You have been resubscribed successfully. ✅"
//                 );
//                 // Continue — send welcome menu below
//             }
//         }

//         // 5. Check opted-in status — skip opted-out contacts
//         if (!$contact->fresh()->opted_in) {
//             Log::info("Contact {$contact->id} is opted out — skipping flow routing");
//             return;
//         }

//         // 6. Resolve active flow builder
//         [$builder, $triggerReason] = $this->resolveFlowBuilderWithReason($company, $dto->text ?? '');

//         // 7. Check active flow session (mid-conversation)
//         $session = FlowSession::where('company_id', $company->id)
//             ->where('phone', $dto->phone)
//             ->where('expires_at', '>', now())
//             ->first();

//         // 8. Interactive reply → match flow node
//         if ($dto->type === 'interactive' && $dto->replyId) {
//             $this->handleFlowReply($company, $contact, $dto, $builder?->id);
//             return;
//         }

//         // 9. ── KEY FIX ──
//         // If a KEYWORD builder matched → always send its welcome menu
//         // regardless of what the text was (it was the trigger word itself)
//         if ($triggerReason === 'keyword' && $builder) {
//             Log::info("Keyword triggered builder {$builder->id} '{$builder->name}' — sending its welcome menu");
//             $session?->delete(); // clear any existing session — fresh start
//             $this->sendWelcomeMenu($company, $dto->phone, $builder->id);
//             return;
//         }

        // // 10. Text greeting OR new conversation → send welcome menu
        // if ($dto->type === 'text' && $this->isGreeting($dto->text)) {
        //     $session?->delete();
        //     $this->sendWelcomeMenu($company, $dto->phone, $builder?->id);
        //     return;
        // }

        // // 11. Mid-session text → try to match current node's children
        // if ($session && $dto->type === 'text') {
//             $matched = $this->matchTextToNode($company, $contact, $dto, $session, $builder?->id);
//             if ($matched) return;
//         }

//         // 12. Default fallback response
//         $this->sendText(
//             $company,
//             $dto->phone,
//             "Thank you for your message! 😊 Our team will get back to you shortly.\n\nReply *menu* to see our options."
//         );
//     }

//     // ─── Handle status update (sent/delivered/read/failed) ────────────────────
//     public function handleStatusUpdate(Company $company, StatusUpdateDTO $dto): void
//     {
//         $log = MessageLog::where('wa_message_id', $dto->waMessageId)->first();
//         if ($log) {
//             $updates = ['status' => $dto->status];
//             if ($dto->status === 'delivered') $updates['delivered_at'] = now();
//             if ($dto->status === 'read')      $updates['read_at']      = now();
//             $log->update($updates);
//         }

//         if (in_array($dto->status, ['delivered', 'read', 'failed'])) {
//             $cc = \App\Models\CampaignContact::where('wa_message_id', $dto->waMessageId)->first();
//             if ($cc) {
//                 $cc->update([
//                     'status'       => $dto->status,
//                     'delivered_at' => $dto->status === 'delivered' ? now() : $cc->delivered_at,
//                     'read_at'      => $dto->status === 'read'      ? now() : $cc->read_at,
//                 ]);

//                 \App\Models\Campaign::where('id', $cc->campaign_id)
//                     ->increment($dto->status === 'failed' ? 'failed' : $dto->status);
//             }
//         }
//     }

//     // ─── Resolve which flow builder to use ────────────────────────────────────
//     // Priority: keyword → season (date range) → default active
//     private function resolveFlowBuilderWithReason(Company $company, string $text): array
//     {
//         $keyword = strtolower(trim($text));
//         $now     = now();

//         // ── 1. Keyword match ──────────────────────────────────────────────
//         $keywordBuilders = FlowBuilder::where('company_id', $company->id)
//             ->where('trigger_type', 'keyword')
//             ->where('is_active', true)
//             ->get();

//         foreach ($keywordBuilders as $builder) {
//             $keywords = $builder->trigger_keywords ?? [];
//             if (is_string($keywords)) {
//                 $keywords = json_decode($keywords, true) ?? [];
//             }

//             $lowerKeywords = array_map('strtolower', array_map('trim', $keywords));

//             if (in_array($keyword, $lowerKeywords, true)) {
//                 Log::info("Keyword flow triggered: builder={$builder->id} name='{$builder->name}' keyword='{$keyword}'");
//                 return [$builder, 'keyword'];
//             }
//         }

//         // ── 2. Season / date-range match (IST-aware) ──────────────────────
//         $seasonBuilder = FlowBuilder::where('company_id', $company->id)
//             ->where('trigger_type', 'season')
//             ->where('is_active', true)
//             ->where('active_from',  '<=', $now)
//             ->where('active_until', '>=', $now)
//             ->orderByDesc('active_from')
//             ->first();

//         if ($seasonBuilder) {
//             Log::info("Season flow active: builder={$seasonBuilder->id} name='{$seasonBuilder->name}'");
//             return [$seasonBuilder, 'season'];
//         }

//         // ── 3. Default fallback ────────────────────────────────────────────
//         $default = FlowBuilder::where('company_id', $company->id)
//             ->where('trigger_type', 'default')
//             ->where('is_active', true)
//             ->first();

//         if (!$default) {
//             Log::warning("No active flow builder found for company={$company->id}");
//             return [null, null];
//         }

//         return [$default, 'default'];
//     }

//     // ─── Match reply_id to flow node → send response → maybe create lead ─────
//     private function handleFlowReply(
//         Company $company,
//         Contact $contact,
//         InboundMessageDTO $dto,
//         ?int $builderId
//     ): void {

//         Log::info("Handling flow reply: company={$company->id} contact={$contact->id} reply_id={$dto->replyId} builder_id={$builderId}");
//         $query = FlowNode::where('company_id', $company->id)
//             ->where('reply_id', $dto->replyId)
//             ->where('is_active', true);

//         if ($builderId) {
//             $query->where('flow_builder_id', $builderId);
//         }

//         $node = $query->first();

//         if (!$node) {
//             Log::warning("No flow node found for reply_id={$dto->replyId} company={$company->id}");
//             $this->sendFallbackMessage($company, $dto->phone);
//             return;
//         }

//         $node->increment('trigger_count');

//         Log::info("Flow node triggered: node={$node->id} reply_id={$dto->replyId} company={$company->id}");

//         FlowSession::updateOrCreate(
//             ['company_id' => $company->id, 'phone' => $dto->phone],
//             [
//                 'contact_id'      => $contact->id,
//                 'current_node_id' => $node->id,
//                 'flow_builder_id' => $node->flow_builder_id,
//                 'context'         => [
//                     'last_reply_id' => $dto->replyId,
//                     'last_title'    => $dto->replyTitle,
//                     'history'       => [$dto->replyId],
//                 ],
//                 'expires_at' => now()->addHours(24),
//             ]
//         );

//         $this->sendNodeResponse($company, $dto->phone, $node);

//         if ($node->lead_category) {
//             $this->autoCreateLead($company, $contact, $node);
//         }
//     }

//     // ─── Try matching text input to current node's children ───────────────────
//     private function matchTextToNode(
//         Company $company,
//         Contact $contact,
//         InboundMessageDTO $dto,
//         FlowSession $session,
//         ?int $builderId
//     ): bool {
//         if (!$session->current_node_id) return false;

//         $currentNode = FlowNode::find($session->current_node_id);
//         if (!$currentNode) return false;

//         $children = $currentNode->children()->where('is_active', true)->get();
//         $msgText  = strtolower(trim($dto->text ?? ''));

//         $matched = $children->first(function ($child) use ($msgText) {
//             return strtolower(trim($child->title)) === $msgText
//                 || strtolower(trim($child->reply_id)) === $msgText;
//         });

//         if ($matched) {
//             $fakeDto = new InboundMessageDTO(
//                 phone: $dto->phone,
//                 waId: $dto->waId,
//                 messageId: $dto->messageId,
//                 type: 'interactive',
//                 text: $dto->text,
//                 interactiveType: 'button_reply',
//                 replyId: $matched->reply_id,
//                 replyTitle: $matched->title,
//                 rawPayload: $dto->rawPayload,
//                 caption: $dto->caption,
//             );
//             $this->handleFlowReply($company, $contact, $fakeDto, $builderId);
//             return true;
//         }

//         // Re-send current node options instead of matching nothing
//         $this->sendNodeResponse($company, $dto->phone, $currentNode);
//         return true;
//     }

//     // ─── Send welcome menu (root node) ────────────────────────────────────────
//     private function sendWelcomeMenu(Company $company, string $phone, ?int $builderId): void
//     {
//         $query = FlowNode::where('company_id', $company->id)
//             ->whereNull('parent_id')
//             ->where('is_active', true);

//         if ($builderId) {
//             $query->where('flow_builder_id', $builderId);
//         }

//         $root = $query->orderBy('sort_order')->first();

//         if (!$root) {
//             $this->sendText($company, $phone, "Welcome! 👋 How can we help you today?");
//             return;
//         }

//         $root->increment('trigger_count');
//         $this->sendNodeResponse($company, $phone, $root);
//     }

//     // ─── Build and send node response ──────────────────────────────────────────
//     // Order of precedence: dynamic (API-driven) → multi-message → single rich media
//     // → leaf text/media → button/list with static children.
//     private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
//     {
//         // ── Dynamic node: options fetched live from an external API ──────────
//         if ($node->is_dynamic && $node->dynamic_api_url) {
//             if ($node->message) {
//                 $this->sendText($company, $phone, $node->message);
//                 usleep(100000);
//             }

//             $options = $this->resolveDynamicOptions($node);

//             if ($options === null) {
//                 $this->sendFallbackMessage($company, $phone);
//                 return;
//             }

//             $this->sendDynamicOptions($company, $phone, $node, $options);
//             return;
//         }

//         $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

//         // ── Multi-message node: send each block one-by-one, then options last ──
//         if ($node->hasMultipleMessages()) {
//             $this->sendMultipleMessages($company, $phone, $node->multi_messages);

//             if ($children->isNotEmpty()) {
//                 usleep(100000); // 0.5s gap before the buttons/list
//                 if ($node->type === 'button' && $children->count() <= 3) {
//                     $this->sendButton($company, $phone, '👇 Please select an option:', $children);
//                 } else {
//                     $this->sendList($company, $phone, '👇 Please select an option:', $children);
//                 }
//             }

//             if ($node->message) {
//                 usleep(100000);
//                 $this->sendText($company, $phone, $node->message);
//             }

//             return;
//         }

//         // ── Single rich-media node (no multi_messages) ──────────────────────────
//         if ($node->media_type === 'audio' && $node->media_url) {
//             $this->sendAudio($company, $phone, $node->media_url);
//             if ($node->message) {
//                 usleep(100000);
//                 $this->sendText($company, $phone, $node->message);
//             }
//             return;
//         }

//         if ($node->media_type === 'location' && $node->location_lat) {
//             $this->sendLocation($company, $phone, $node);
//             return;
//         }

//         if ($children->isEmpty()) {
//             if ($node->media_type && $node->media_url) {
//                 $this->sendSingleMedia($company, $phone, $node->media_type, $node->media_url, $node->media_caption, $node->media_filename);
//             } else {
//                 $this->sendText($company, $phone, $node->message ?: 'Thanks for your reply!');
//             }
//             return;
//         }

//         // ── Button / list with static children ──────────────────────────────────
//         if ($node->type === 'button' && $children->count() <= 3) {
//             $this->sendButtonWithOptionalMedia($company, $phone, $node, $children);
//         } else {
//             $this->sendList($company, $phone, $node->message, $children);
//         }
//     }

//     // ─── Fetch + normalize options for a dynamic node ──────────────────────────
//     private function resolveDynamicOptions(FlowNode $node): ?array
//     {
//         try {
//             $headers = [];
//             if ($node->dynamic_api_headers) {
//                 $decoded = json_decode($node->dynamic_api_headers, true);
//                 if (is_array($decoded)) $headers = $decoded;
//             }

//             $request = Http::withHeaders($headers)->timeout(8);
//             $method  = strtoupper($node->dynamic_api_method ?? 'GET');

//             $response = $method === 'POST'
//                 ? $request->post($node->dynamic_api_url)
//                 : $request->get($node->dynamic_api_url);

//             if (!$response->successful()) {
//                 Log::warning("Dynamic node API failed: node={$node->id} status={$response->status()}");
//                 return null;
//             }

//             $data = $response->json();
//             $rows = (is_array($data) && array_is_list($data)) ? $data : ($data['data'] ?? $data['results'] ?? []);

//             if (!is_array($rows) || empty($rows)) {
//                 Log::warning("Dynamic node API returned no rows: node={$node->id}");
//                 return null;
//             }

//             $labelField = $node->dynamic_label_field ?: 'name';
//             $valueField = $node->dynamic_value_field ?: 'id';

//             $options = collect($rows)->map(fn($row) => [
//                 'title'       => (string) ($row[$labelField] ?? 'Option'),
//                 'reply_id'    => (string) ($row[$valueField] ?? ''),
//                 'description' => $node->dynamic_description_field ? (string) ($row[$node->dynamic_description_field] ?? '') : '',
//                 'image'       => $node->dynamic_image_field ? ($row[$node->dynamic_image_field] ?? null) : null,
//                 'subtitle'    => $node->dynamic_subtitle_field ? ($row[$node->dynamic_subtitle_field] ?? null) : null,
//             ])
//                 ->filter(fn($o) => $o['reply_id'] !== '')
//                 ->take(self::MAX_LIST_ROWS)
//                 ->values()
//                 ->all();

//             return empty($options) ? null : $options;
//         } catch (\Exception $e) {
//             Log::error("Dynamic node fetch exception: node={$node->id} " . $e->getMessage());
//             return null;
//         }
//     }

//     // ─── Send normalized dynamic options as image(s) + button/list ────────────
//     private function sendDynamicOptions(Company $company, string $phone, FlowNode $node, array $options): void
//     {
//         $hasImages = collect($options)->contains(fn($o) => !empty($o['image']));

//         if ($hasImages) {
//             foreach ($options as $i => $opt) {
//                 if ($i > 0) usleep(400000);
//                 if (!empty($opt['image'])) {
//                     $caption = trim(implode("\n", array_filter([$opt['title'], $opt['subtitle'] ?? null, $opt['description'] ?? null])));
//                     $this->sendSingleMedia($company, $phone, 'image', $opt['image'], $caption, null);
//                 }
//             }
//             usleep(400000);
//         }

//         $body = $node->message ?: 'Please choose an option:';

//         if (count($options) <= 3) {
//             $this->sendButtonFromArray($company, $phone, $body, $options);
//         } else {
//             $this->sendListFromArray($company, $phone, $body, $options);
//         }
//     }

//     // ─── Default fallback message when something upstream fails ───────────────
//     private function sendFallbackMessage(Company $company, string $phone): void
//     {
//         $this->sendText(
//             $company,
//             $phone,
//             "Sorry, that option isn't available right now — please try again in a moment, or reply *menu* to start over."
//         );
//     }

//     // ─── Send an array of messages sequentially (text/image/video/document/audio/location) ─
//     private function sendMultipleMessages(Company $company, string $phone, array $messages): void
//     {
//         foreach ($messages as $i => $msg) {
//             if ($i > 0) {
//                 usleep(200000); // WhatsApp doesn't guarantee order without a delay
//             }

//             $type = $msg['type'] ?? 'text';

//             match ($type) {
//                 'text'     => $this->sendText($company, $phone, $msg['content'] ?? $msg['text'] ?? ''),
//                 'image'    => $this->sendSingleMedia($company, $phone, 'image',    $msg['url'], $msg['caption'] ?? null, null, $msg['mime_type'] ?? null),
//                 'video'    => $this->sendSingleMedia($company, $phone, 'video',    $msg['url'], $msg['caption'] ?? null, null, $msg['mime_type'] ?? null),
//                 'document' => $this->sendSingleMedia($company, $phone, 'document', $msg['url'], $msg['caption'] ?? null, $msg['filename'] ?? 'Document.pdf', $msg['mime_type'] ?? null),
//                 'audio'    => $this->sendAudio($company, $phone, $msg['url'], $msg['mime_type'] ?? null),
//                 'location' => $this->dispatch($company, [
//                     'messaging_product' => 'whatsapp',
//                     'to'                => $phone,
//                     'type'              => 'location',
//                     'location'          => [
//                         'latitude'  => $msg['lat'],
//                         'longitude' => $msg['lng'],
//                         'name'      => $msg['name']    ?? '',
//                         'address'   => $msg['address'] ?? '',
//                     ],
//                 ]),
//                 default => null,
//             };
//         }
//     }

//     // ─── Auto-create lead from flow ───────────────────────────────────────────
//     private function autoCreateLead(Company $company, Contact $contact, FlowNode $node): void
//     {
//         $existing = $this->leadRepository->findByContact($contact->id, $company->id);
//         if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
//             Log::info("Lead already active for contact {$contact->id} — skipping auto-create");
//             return;
//         }

//         $dto = CreateLeadDTO::fromFlow(
//             contactId: $contact->id,
//             flowNodeId: $node->id,
//             category: $node->lead_category,
//         );

//         try {
//             $lead = $this->leadRepository->create($company->id, $dto);
//             Log::info("Auto-created lead {$lead->id} for contact {$contact->id} from flow node {$node->id}");
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

//     // ─── Extract readable content from message ────────────────────────────────
//     private function extractContent(InboundMessageDTO $dto): string
//     {
//         return match ($dto->type) {
//             'text'        => $dto->text ?? '',
//             'interactive' => $dto->replyTitle ?? $dto->replyId ?? '[interactive reply]',
//             'image'       => '[Image]' . ($dto->caption ? ': ' . $dto->caption : ''),
//             'audio'       => '[Voice note]',
//             'video'       => '[Video]',
//             'document'    => '[Document]',
//             'sticker'     => '[Sticker]',
//             'location'    => '[Location]',
//             default       => '[' . $dto->type . ']',
//         };
//     }

//     // ─── WhatsApp API helpers ─────────────────────────────────────────────────
//     private function sendText(Company $company, string $phone, string $text): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'text',
//             'text'              => ['body' => $text, 'preview_url' => false],
//         ]);
//     }

//     private function sendButton(Company $company, string $phone, string $body, $children): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => [
//                 'type' => 'button',
//                 'body' => ['text' => $body],
//                 'action' => [
//                     'buttons' => $children->map(fn($c) => [
//                         'type'  => 'reply',
//                         'reply' => [
//                             'id'    => mb_substr($c->reply_id, 0, 256),
//                             'title' => mb_substr($c->title, 0, 20),
//                         ],
//                     ])->values()->all(),
//                 ],
//             ],
//         ]);
//     }

//     // ── Button variant for dynamic (array-based) options ──────────────────
//     private function sendButtonFromArray(Company $company, string $phone, string $body, array $options): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => [
//                 'type' => 'button',
//                 'body' => ['text' => $body],
//                 'action' => [
//                     'buttons' => collect($options)->map(fn($o) => [
//                         'type'  => 'reply',
//                         'reply' => [
//                             'id'    => mb_substr($o['reply_id'], 0, 256),
//                             'title' => mb_substr($o['title'], 0, 20),
//                         ],
//                     ])->values()->all(),
//                 ],
//             ],
//         ]);
//     }

//     private function sendList(Company $company, string $phone, string $body, $children): void
//     {
//         $this->dispatch($company, [
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
//                             'id'          => mb_substr($c->reply_id, 0, 200),
//                             'title'       => mb_substr($c->title, 0, 24),
//                             'description' => mb_substr($c->message ?? '', 0, 72),
//                         ])->values()->all(),
//                     ]],
//                 ],
//             ],
//         ]);
//     }

//     // ── List variant for dynamic (array-based) options ─────────────────────
//     private function sendListFromArray(Company $company, string $phone, string $body, array $options): void
//     {
//         $this->dispatch($company, [
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
//                         'rows'  => collect($options)->map(fn($o) => [
//                             'id'          => mb_substr($o['reply_id'], 0, 200),
//                             'title'       => mb_substr($o['title'], 0, 24),
//                             'description' => mb_substr($o['description'] ?? $o['subtitle'] ?? '', 0, 72),
//                         ])->values()->all(),
//                     ]],
//                 ],
//             ],
//         ]);
//     }

//     // ── Button with optional media header (single node, no multi_messages) ─
//     private function sendButtonWithOptionalMedia(Company $company, string $phone, FlowNode $node, $children): void
//     {
//         $interactive = [
//             'type' => 'button',
//             'body' => ['text' => $node->message],
//             'action' => [
//                 'buttons' => $children->map(fn($c) => [
//                     'type'  => 'reply',
//                     'reply' => [
//                         'id'    => mb_substr($c->reply_id, 0, 256),
//                         'title' => mb_substr($c->title,    0, 20),
//                     ],
//                 ])->values()->all(),
//             ],
//         ];

//         if ($node->media_type && $node->media_url && in_array($node->media_type, ['image', 'video', 'document'])) {
//             $mediaId = $this->uploadMediaToMeta($company, $node->media_url, $this->guessMimeType($node->media_type, $node->media_url));
//             $header  = ['type' => strtoupper($node->media_type)];
//             $header[strtolower($node->media_type)] = $mediaId ? ['id' => $mediaId] : ['link' => $node->media_url];
//             $interactive['header'] = $header;
//         }

//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => $interactive,
//         ]);
//     }

//     // ── Send image / video / document ───────────────────────────────────────
//     // Uploads to Meta's Media API first and sends by `id` — this is the
//     // reliable path recommended by WhatsApp. Only falls back to `link` if the
//     // upload itself fails (e.g. Meta's API is down), since a `link` send
//     // requires META's servers to fetch our URL directly, which silently fails
//     // behind broken storage:link setups, bad SSL chains, or bot-blocking WAFs —
//     // exactly the "text sends, media doesn't" symptom this replaces.
//     private function sendSingleMedia(Company $company, string $phone, string $type, string $url, ?string $caption, ?string $filename, ?string $mimeType = null): void
//     {
//         $mimeType = $mimeType ?: $this->guessMimeType($type, $url);
//         $mediaId  = $this->uploadMediaToMeta($company, $url, $mimeType);

//         $payload = $mediaId ? ['id' => $mediaId] : ['link' => $url];
//         if ($caption)  $payload['caption']  = $caption;
//         if ($filename) $payload['filename'] = $filename;

//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => $type,
//             $type               => $payload,
//         ]);
//     }

//     // ── Send audio ────────────────────────────────────────────────────────
//     private function sendAudio(Company $company, string $phone, string $url, ?string $mimeType = null): void
//     {
//         $mimeType = $mimeType ?: 'audio/mpeg';
//         $mediaId  = $this->uploadMediaToMeta($company, $url, $mimeType);

//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'audio',
//             'audio'             => $mediaId ? ['id' => $mediaId] : ['link' => $url],
//         ]);
//     }

//     // ── Send location ─────────────────────────────────────────────────────
//     private function sendLocation(Company $company, string $phone, FlowNode $node): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'location',
//             'location'          => [
//                 'latitude'  => $node->location_lat,
//                 'longitude' => $node->location_lng,
//                 'name'      => $node->location_name    ?? '',
//                 'address'   => $node->location_address ?? '',
//             ],
//         ]);
//     }

//     // ── Upload a locally-hosted file to Meta's Media API, return its media id ──
//     // Fetches the bytes from our own storage URL (server-to-server, so it works
//     // even if Meta's own crawler would be blocked) and re-uploads them to
//     // /{phone_number_id}/media. Returns null on any failure so callers can fall
//     // back to `link` instead of dropping the message entirely.
//     private function uploadMediaToMeta(Company $company, string $url, string $mimeType): ?string
//     {
//         if (!$company->wa_phone_id || !$company->decrypt_wa_access_token) return null;

//         try {
//             $token = $company->decrypt_wa_access_token;
//             try { $token = decrypt($token); } catch (\Exception) { /* already plain */ }

//             $fileResponse = Http::timeout(15)->get($url);
//             if (!$fileResponse->successful()) {
//                 Log::error("uploadMediaToMeta: could not fetch source file {$url} — status={$fileResponse->status()}");
//                 return null;
//             }

//             $response = Http::withToken($token)
//                 ->timeout(20)
//                 ->attach('file', $fileResponse->body(), basename(parse_url($url, PHP_URL_PATH) ?: 'file'), ['Content-Type' => $mimeType])
//                 ->post("https://graph.facebook.com/v21.0/{$company->wa_phone_id}/media", [
//                     'messaging_product' => 'whatsapp',
//                     'type'              => $mimeType,
//                 ]);

//             if ($response->successful()) {
//                 return $response->json('id');
//             }

//             Log::error("uploadMediaToMeta: Meta upload failed for {$url} — " . $response->body());
//             return null;
//         } catch (\Exception $e) {
//             Log::error("uploadMediaToMeta exception for {$url}: " . $e->getMessage());
//             return null;
//         }
//     }

//     // ── Best-effort mime type guess from node type + file extension ────────
//     private function guessMimeType(string $type, string $url): string
//     {
//         $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

//         return match (true) {
//             $type === 'image' && $ext === 'png'          => 'image/png',
//             $type === 'image'                            => 'image/jpeg',
//             $type === 'video'                            => 'video/mp4',
//             $type === 'audio' && $ext === 'ogg'           => 'audio/ogg',
//             $type === 'audio'                             => 'audio/mpeg',
//             $type === 'document' && $ext === 'pdf'        => 'application/pdf',
//             $type === 'document' && $ext === 'doc'        => 'application/msword',
//             $type === 'document' && $ext === 'docx'       => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
//             default                                       => 'application/octet-stream',
//         };
//     }

//     // ─── POST to Meta Graph API (single dispatch path — no more duplicate) ────
//     private function dispatch(Company $company, array $payload): void
//     {
//         if (!$company->wa_phone_id || !$company->decrypt_wa_access_token) {
//             Log::error("Company {$company->id} has no WA credentials — cannot send message");
//             return;
//         }

//         try {
//             $token = $company->decrypt_wa_access_token;
//             try {
//                 $token = decrypt($token);
//             } catch (\Exception) { /* already plain */
//             }

//             $response = Http::withToken($token)
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
//                     'content'       => $this->extractOutboundContent($payload),
//                     'status'        => 'sent',
//                     'cost'          => 1,
//                 ]);

//                 Log::info("WA message sent: waId={$waId} to={$payload['to']} company={$company->id}");
//             } else {
//                 $error = $response->json('error.message') ?? 'Unknown error';
//                 $code  = $response->json('error.code');
//                 Log::error("WA send failed: [{$code}] {$error}", [
//                     'company'  => $company->id,
//                     'to'       => $payload['to'],
//                     'type'     => $payload['type'],
//                     'response' => $response->json(),
//                 ]);

//                 // Persist failures too — previously only successes were logged to
//                 // MessageLog, making "media silently failed" invisible in the UI.
//                 MessageLog::create([
//                     'company_id' => $company->id,
//                     'direction'  => 'outbound',
//                     'type'       => $payload['type'],
//                     'phone'      => $payload['to'],
//                     'content'    => $this->extractOutboundContent($payload) . " [FAILED: {$error}]",
//                     'status'     => 'failed',
//                     'cost'       => 0,
//                 ]);
//             }
//         } catch (\Exception $e) {
//             Log::error("WA dispatch exception: " . $e->getMessage(), ['company' => $company->id]);
//         }
//     }

//     private function extractOutboundContent(array $payload): string
//     {
//         return match ($payload['type']) {
//             'text'        => $payload['text']['body'] ?? '',
//             'image'       => '[Image]' . (!empty($payload['image']['caption']) ? ': ' . $payload['image']['caption'] : ''),
//             'video'       => '[Video]' . (!empty($payload['video']['caption']) ? ': ' . $payload['video']['caption'] : ''),
//             'document'    => '[Document]' . (!empty($payload['document']['filename']) ? ': ' . $payload['document']['filename'] : ''),
//             'audio'       => '[Audio message]',
//             'location'    => '[Location]' . (!empty($payload['location']['name']) ? ': ' . $payload['location']['name'] : ''),
//             'interactive' => match ($payload['interactive']['type'] ?? '') {
//                 'button' => $payload['interactive']['body']['text'] ?? '[button message]',
//                 'list'   => $payload['interactive']['body']['text'] ?? '[list message]',
//                 default  => '[interactive]',
//             },
//             default => '[' . $payload['type'] . ']',
//         };
//     }

//     private function isGreeting(?string $text): bool
//     {
//         if (!$text) return false;
//         return in_array(strtolower(trim($text)), self::GREETING_KEYWORDS);
//     }
// }
// namespace App\Modules\Webhook\Services;

// use App\Models\Company;
// use App\Models\Contact;
// use App\Models\FlowBuilder;
// use App\Models\FlowNode;
// use App\Models\FlowSession;
// use App\Models\MessageBlacklist;
// use App\Models\MessageLog;
// use App\Modules\Lead\DTOs\CreateLeadDTO;
// use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
// use App\Modules\Webhook\DTOs\InboundMessageDTO;
// use App\Modules\Webhook\DTOs\StatusUpdateDTO;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;

// class WebhookService
// {
//     // ── STOP / unsubscribe keywords ────────────────────────────────────────
//     private const OPT_OUT_KEYWORDS = [
//         'stop',
//         'unsubscribe',
//         'optout',
//         'opt out',
//         'quit',
//         'cancel',
//         'remove',
//         'end',
//         'block',
//         'no more',
//     ];

//     // ── Re-subscribe keywords ──────────────────────────────────────────────
//     private const OPT_IN_KEYWORDS = [
//         'start',
//         'subscribe',
//         'yes',
//         'optin',
//         'opt in',
//         'resume',
//         'restart',
//         'begin',
//     ];

//     // ── Greeting keywords → send welcome menu ──────────────────────────────
//     private const GREETING_KEYWORDS = [
//         'hi',
//         'hello',
//         'hey',
//         'start',
//         'menu',
//         'hai',
//         'helo',
//         'hii',
//         'hola',
//         'namaste',
//         'vanakkam',
//         'ഹലോ',
//     ];

//     // Options row cap enforced by WhatsApp for interactive lists
//     private const MAX_LIST_ROWS = 10;

//     public function __construct(
//         private readonly LeadRepositoryInterface $leadRepository,
//     ) {}

//     // ─── Handle inbound message ───────────────────────────────────────────────
//     public function handleInbound(Company $company, InboundMessageDTO $dto): void
//     {
//         // 1. Blacklist check — silent ignore, no reply, no lead
//         $isBlacklisted = MessageBlacklist::where('company_id', $company->id)
//             ->where('phone', $dto->phone)
//             ->exists();

//         if ($isBlacklisted) {
//             Log::info("Blacklisted number {$dto->phone} ignored for company {$company->id}");
//             return;
//         }

//         // 2. Find or create contact
//         $contact = $this->resolveContact($company, $dto);

//         // 3. Log inbound message with full content
//         MessageLog::create([
//             'company_id'    => $company->id,
//             'contact_id'    => $contact->id,
//             'wa_message_id' => $dto->messageId,
//             'direction'     => 'inbound',
//             'type'          => $dto->type,
//             'phone'         => $dto->phone,
//             'content'       => $this->extractContent($dto),
//             'status'        => 'received',
//             'cost'          => 0,
//         ]);

//         $contact->update(['last_message_at' => now()]);

//         // 4. STOP / opt-out handling (before any flow routing)
//         if ($dto->type === 'text') {
//             $msgText = strtolower(trim($dto->text ?? ''));

//             if (in_array($msgText, self::OPT_OUT_KEYWORDS)) {
//                 $contact->update(['opted_in' => false, 'opted_out_at' => now()]);
//                 $this->sendText(
//                     $company,
//                     $dto->phone,
//                     "You have been unsubscribed. ✅\n\nYou will no longer receive messages from us.\n\nReply *START* anytime to resubscribe."
//                 );
//                 Log::info("Contact {$contact->id} opted out via keyword: {$msgText}");
//                 return;
//             }

//             if (in_array($msgText, self::OPT_IN_KEYWORDS) && !$contact->opted_in) {
//                 $contact->update(['opted_in' => true, 'opted_out_at' => null]);
//                 $this->sendText(
//                     $company,
//                     $dto->phone,
//                     "Welcome back! 👋 You have been resubscribed successfully. ✅"
//                 );
//                 // Continue — send welcome menu below
//             }
//         }

//         // 5. Check opted-in status — skip opted-out contacts
//         if (!$contact->fresh()->opted_in) {
//             Log::info("Contact {$contact->id} is opted out — skipping flow routing");
//             return;
//         }

//         // 6. Resolve active flow builder
//         // $builder = $this->resolveFlowBuilder($company, $dto->text ?? '');

//         [$builder, $triggerReason] = $this->resolveFlowBuilderWithReason($company, $dto->text ?? '');

//         // 7. Check active flow session (mid-conversation)
//         $session = FlowSession::where('company_id', $company->id)
//             ->where('phone', $dto->phone)
//             ->where('expires_at', '>', now())
//             ->first();


//         // 8. Interactive reply → match flow node
//         if ($dto->type === 'interactive' && $dto->replyId) {
//             $this->handleFlowReply($company, $contact, $dto, $builder?->id);
//             return;
//         }

//          // 9. ── KEY FIX ──
//         // If a KEYWORD builder matched → always send its welcome menu
//         // regardless of what the text was (it was the trigger word itself)
//         if ($triggerReason === 'keyword' && $builder) {
//             Log::info("Keyword triggered builder {$builder->id} '{$builder->name}' — sending its welcome menu");
//             $session?->delete(); // clear any existing session — fresh start
//             $this->sendWelcomeMenu($company, $dto->phone, $builder->id);
//             return;
//         }

//         // 10. Text greeting OR new conversation → send welcome menu
//         if ($dto->type === 'text' && $this->isGreeting($dto->text)) {
//             $session?->delete();
//             $this->sendWelcomeMenu($company, $dto->phone, $builder?->id);
//             return;
//         }

//         // 11. Mid-session text → try to match current node's children
//         if ($session && $dto->type === 'text') {
//             $matched = $this->matchTextToNode($company, $contact, $dto, $session, $builder?->id);
//             if ($matched) return;
//         }

//         // 12. Default fallback response
//         $this->sendText(
//             $company,
//             $dto->phone,
//             "Thank you for your message! 😊 Our team will get back to you shortly.\n\nReply *menu* to see our options."
//         );
//     }

//     // ─── Handle status update (sent/delivered/read/failed) ────────────────────
//     public function handleStatusUpdate(Company $company, StatusUpdateDTO $dto): void
//     {
//         $log = MessageLog::where('wa_message_id', $dto->waMessageId)->first();
//         if ($log) {
//             $updates = ['status' => $dto->status];
//             if ($dto->status === 'delivered') $updates['delivered_at'] = now();
//             if ($dto->status === 'read')      $updates['read_at']      = now();
//             $log->update($updates);
//         }

//         if (in_array($dto->status, ['delivered', 'read', 'failed'])) {
//             $cc = \App\Models\CampaignContact::where('wa_message_id', $dto->waMessageId)->first();
//             if ($cc) {
//                 $cc->update([
//                     'status'       => $dto->status,
//                     'delivered_at' => $dto->status === 'delivered' ? now() : $cc->delivered_at,
//                     'read_at'      => $dto->status === 'read'      ? now() : $cc->read_at,
//                 ]);

//                 \App\Models\Campaign::where('id', $cc->campaign_id)
//                     ->increment($dto->status === 'failed' ? 'failed' : $dto->status);
//             }
//         }
//     }

//     // ─── Resolve which flow builder to use ────────────────────────────────────
//     // Priority: keyword → season (date range) → default active

//     private function resolveFlowBuilderWithReason(Company $company, string $text): array
//     {
//         $keyword = strtolower(trim($text));
//         $now     = now();

//         // ── 1. Keyword match ──────────────────────────────────────────────
//         $keywordBuilders = FlowBuilder::where('company_id', $company->id)
//             ->where('trigger_type', 'keyword')
//             ->where('is_active', true)
//             ->get();

//         foreach ($keywordBuilders as $builder) {
//             $keywords = $builder->trigger_keywords ?? [];
//             if (is_string($keywords)) {
//                 $keywords = json_decode($keywords, true) ?? [];
//             }

//             $lowerKeywords = array_map('strtolower', array_map('trim', $keywords));

//             if (in_array($keyword, $lowerKeywords, true)) {
//                 Log::info("Keyword flow triggered: builder={$builder->id} name='{$builder->name}' keyword='{$keyword}'");
//                 return [$builder, 'keyword']; // ← returns reason
//             }
//         }

//         // ── 2. Season / date-range match (IST-aware) ──────────────────────
//         $seasonBuilder = FlowBuilder::where('company_id', $company->id)
//             ->where('trigger_type', 'season')
//             ->where('is_active', true)
//             ->where('active_from',  '<=', $now)
//             ->where('active_until', '>=', $now)
//             ->orderByDesc('active_from')
//             ->first();

//         if ($seasonBuilder) {
//             Log::info("Season flow active: builder={$seasonBuilder->id} name='{$seasonBuilder->name}'");
//             return [$seasonBuilder, 'season'];
//         }

//         // ── 3. Default fallback ────────────────────────────────────────────
//         $default = FlowBuilder::where('company_id', $company->id)
//             ->where('trigger_type', 'default')
//             ->where('is_active', true)
//             ->first();

//         if (!$default) {
//             Log::warning("No active flow builder found for company={$company->id}");
//             return [null, null];
//         }

//         return [$default, 'default'];
//     }

//     // private function resolveFlowBuilder(Company $company, string $text): ?FlowBuilder
//     // {
//         // $keyword = strtolower(trim($text));
//         // $now     = now();

//         // $keywordBuilders = FlowBuilder::where('company_id', $company->id)
//         //     ->where('trigger_type', 'keyword')
//         //     ->where('is_active', true)
//         //     ->get();

//         // foreach ($keywordBuilders as $builder) {
//         //     // $keywords = json_decode($builder->trigger_keywords ?? '[]', true);
//         //     $keywords = $builder->trigger_keywords ?? [];
//         //     if (in_array($keyword, array_map('strtolower', $keywords))) {
//         //         Log::info("Keyword flow triggered: builder {$builder->id} for keyword '{$keyword}'");
//         //         return $builder;
//         //     }
//         // }

//         // $seasonBuilder = FlowBuilder::where('company_id', $company->id)
//         //     ->where('trigger_type', 'season')
//         //     ->where('is_active', true)
//         //     ->where('active_from',  '<=', $now)
//         //     ->where('active_until', '>=', $now)
//         //     ->first();

//         // if ($seasonBuilder) {
//         //     Log::info(
//         //         "Season flow active: builder={$seasonBuilder->id} name='{$seasonBuilder->name}'" .
//         //             " from={$seasonBuilder->active_from->toDateTimeString()}" .
//         //             " until={$seasonBuilder->active_until->toDateTimeString()}" .
//         //             " now_ist={$now->toDateTimeString()}"
//         //     );
//         //     return $seasonBuilder;
//         // }

//         // // ── 3. Default active flow (fallback) ────────────────────────────
//         // $default = FlowBuilder::where('company_id', $company->id)
//         //     ->where('trigger_type', 'default')
//         //     ->where('is_active', true)
//         //     ->first();

//         // if (!$default) {
//         //     Log::warning("No active flow builder found for company={$company->id}");
//         // }

//         // return $default;
//     // }

//     // ─── Match reply_id to flow node → send response → maybe create lead ─────
//     private function handleFlowReply(
//         Company $company,
//         Contact $contact,
//         InboundMessageDTO $dto,
//         ?int $builderId
//     ): void {

//         Log::info("Handling flow reply: company={$company->id} contact={$contact->id} reply_id={$dto->replyId} builder_id={$builderId}");
//         $query = FlowNode::where('company_id', $company->id)
//             ->where('reply_id', $dto->replyId)
//             ->where('is_active', true);

//         if ($builderId) {
//             $query->where('flow_builder_id', $builderId);
//         }

//         $node = $query->first();

//         if (!$node) {
//             Log::warning("No flow node found for reply_id={$dto->replyId} company={$company->id}");
//             $this->sendFallbackMessage($company, $dto->phone);
//             return;
//         }

//         $node->increment('trigger_count');

//         Log::info("Flow node triggered: node={$node->id} reply_id={$dto->replyId} company={$company->id}");
//         Log::info($node);

//         FlowSession::updateOrCreate(
//             ['company_id' => $company->id, 'phone' => $dto->phone],
//             [
//                 'contact_id'      => $contact->id,
//                 'current_node_id' => $node->id,
//                 'flow_builder_id' => $node->flow_builder_id,
//                 'context'         => [
//                     'last_reply_id' => $dto->replyId,
//                     'last_title'    => $dto->replyTitle,
//                     'history'       => [$dto->replyId],
//                 ],
//                 'expires_at' => now()->addHours(24),
//             ]
//         );

//         $this->sendNodeResponse($company, $dto->phone, $node);

//         if ($node->lead_category) {
//             $this->autoCreateLead($company, $contact, $node);
//         }
//     }

//     // ─── Try matching text input to current node's children ───────────────────
//     private function matchTextToNode(
//         Company $company,
//         Contact $contact,
//         InboundMessageDTO $dto,
//         FlowSession $session,
//         ?int $builderId
//     ): bool {
//         if (!$session->current_node_id) return false;

//         $currentNode = FlowNode::find($session->current_node_id);
//         if (!$currentNode) return false;

//         $children = $currentNode->children()->where('is_active', true)->get();
//         $msgText  = strtolower(trim($dto->text ?? ''));

//         $matched = $children->first(function ($child) use ($msgText) {
//             return strtolower(trim($child->title)) === $msgText
//                 || strtolower(trim($child->reply_id)) === $msgText;
//         });

//         if ($matched) {
//             $fakeDto = new InboundMessageDTO(
//                 phone: $dto->phone,
//                 waId: $dto->waId,
//                 messageId: $dto->messageId,
//                 type: 'interactive',
//                 text: $dto->text,
//                 interactiveType: 'button_reply',
//                 replyId: $matched->reply_id,
//                 replyTitle: $matched->title,
//                 rawPayload: $dto->rawPayload,
//                 caption: $dto->caption,
//             );
//             $this->handleFlowReply($company, $contact, $fakeDto, $builderId);
//             return true;
//         }

//         // Re-send current node options instead of matching nothing
//         $this->sendNodeResponse($company, $dto->phone, $currentNode);
//         return true;
//     }

//     // ─── Send welcome menu (root node) ────────────────────────────────────────
//     private function sendWelcomeMenu(Company $company, string $phone, ?int $builderId): void
//     {
//         $query = FlowNode::where('company_id', $company->id)
//             ->whereNull('parent_id')
//             ->where('is_active', true);

//         if ($builderId) {
//             $query->where('flow_builder_id', $builderId);
//         }

//         $root = $query->orderBy('sort_order')->first();

//         if (!$root) {
//             $this->sendText($company, $phone, "Welcome! 👋 How can we help you today?");
//             return;
//         }

//         $root->increment('trigger_count');
//         $this->sendNodeResponse($company, $phone, $root);
//     }

//     // ─── Build and send node response ──────────────────────────────────────────
//     // Order of precedence: dynamic (API-driven) → multi-message → single rich media
//     // → leaf text/media → button/list with static children.
//     private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
//     {
//         // ── Dynamic node: options fetched live from an external API ──────────
//         if ($node->is_dynamic && $node->dynamic_api_url) {
//             if ($node->message) {
//                 $this->sendText($company, $phone, $node->message);
//                 usleep(300000);
//             }

//             $options = $this->resolveDynamicOptions($node);

//             if ($options === null) {
//                 $this->sendFallbackMessage($company, $phone);
//                 return;
//             }

//             $this->sendDynamicOptions($company, $phone, $node, $options);
//             return;
//         }

//         $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

//         // ── Multi-message node: send each block one-by-one, then options last ──
//         if ($node->hasMultipleMessages()) {
//             $this->sendMultipleMessages($company, $phone, $node->multi_messages);

//             if ($children->isNotEmpty()) {
//                 usleep(500000); // 0.5s gap before the buttons/list
//                 if ($node->type === 'button' && $children->count() <= 3) {
//                     $this->sendButton($company, $phone, '👇 Please select an option:', $children);
//                 } else {
//                     $this->sendList($company, $phone, '👇 Please select an option:', $children);
//                 }
//             }
//             return;
//         }

//         // ── Single rich-media node (no multi_messages) ──────────────────────────
//         if ($node->media_type === 'audio' && $node->media_url) {
//             $this->sendAudio($company, $phone, $node->media_url);
//             if ($node->message) {
//                 usleep(300000);
//                 $this->sendText($company, $phone, $node->message);
//             }
//             return;
//         }

//         if ($node->media_type === 'location' && $node->location_lat) {
//             $this->sendLocation($company, $phone, $node);
//             return;
//         }

//         if ($children->isEmpty()) {
//             if ($node->media_type && $node->media_url) {
//                 $this->sendSingleMedia($company, $phone, $node->media_type, $node->media_url, $node->media_caption, $node->media_filename);
//             } else {
//                 $this->sendText($company, $phone, $node->message ?: 'Thanks for your reply!');
//             }
//             return;
//         }

//         // ── Button / list with static children ──────────────────────────────────
//         if ($node->type === 'button' && $children->count() <= 3) {
//             $this->sendButtonWithOptionalMedia($company, $phone, $node, $children);
//         } else {
//             $this->sendList($company, $phone, $node->message, $children);
//         }
//     }

//     // ─── Fetch + normalize options for a dynamic node ──────────────────────────
//     // Returns null on any failure (bad response, timeout, empty payload) so the
//     // caller can fall back to a friendly message instead of a dead end.
//     private function resolveDynamicOptions(FlowNode $node): ?array
//     {
//         try {
//             $headers = [];
//             if ($node->dynamic_api_headers) {
//                 $decoded = json_decode($node->dynamic_api_headers, true);
//                 if (is_array($decoded)) $headers = $decoded;
//             }

//             $request = Http::withHeaders($headers)->timeout(8);
//             $method  = strtoupper($node->dynamic_api_method ?? 'GET');

//             $response = $method === 'POST'
//                 ? $request->post($node->dynamic_api_url)
//                 : $request->get($node->dynamic_api_url);

//             if (!$response->successful()) {
//                 Log::warning("Dynamic node API failed: node={$node->id} status={$response->status()}");
//                 return null;
//             }

//             $data = $response->json();
//             $rows = (is_array($data) && array_is_list($data)) ? $data : ($data['data'] ?? $data['results'] ?? []);

//             if (!is_array($rows) || empty($rows)) {
//                 Log::warning("Dynamic node API returned no rows: node={$node->id}");
//                 return null;
//             }

//             $labelField = $node->dynamic_label_field ?: 'name';
//             $valueField = $node->dynamic_value_field ?: 'id';

//             $options = collect($rows)->map(fn($row) => [
//                 'title'       => (string) ($row[$labelField] ?? 'Option'),
//                 'reply_id'    => (string) ($row[$valueField] ?? ''),
//                 'description' => $node->dynamic_description_field ? (string) ($row[$node->dynamic_description_field] ?? '') : '',
//                 'image'       => $node->dynamic_image_field ? ($row[$node->dynamic_image_field] ?? null) : null,
//                 'subtitle'    => $node->dynamic_subtitle_field ? ($row[$node->dynamic_subtitle_field] ?? null) : null,
//             ])
//                 ->filter(fn($o) => $o['reply_id'] !== '')
//                 ->take(self::MAX_LIST_ROWS)
//                 ->values()
//                 ->all();

//             return empty($options) ? null : $options;
//         } catch (\Exception $e) {
//             Log::error("Dynamic node fetch exception: node={$node->id} " . $e->getMessage());
//             return null;
//         }
//     }

//     // ─── Send normalized dynamic options as image(s) + button/list ────────────
//     private function sendDynamicOptions(Company $company, string $phone, FlowNode $node, array $options): void
//     {
//         $hasImages = collect($options)->contains(fn($o) => !empty($o['image']));

//         // WhatsApp list/button rows can't carry an image — send one photo per
//         // option first (caption = title/subtitle/description), then the picker.
//         if ($hasImages) {
//             foreach ($options as $i => $opt) {
//                 if ($i > 0) usleep(400000);
//                 if (!empty($opt['image'])) {
//                     $caption = trim(implode("\n", array_filter([$opt['title'], $opt['subtitle'] ?? null, $opt['description'] ?? null])));
//                     $this->sendSingleMedia($company, $phone, 'image', $opt['image'], $caption, null);
//                 }
//             }
//             usleep(400000);
//         }

//         $body = $node->message ?: 'Please choose an option:';

//         if (count($options) <= 3) {
//             $this->sendButtonFromArray($company, $phone, $body, $options);
//         } else {
//             $this->sendListFromArray($company, $phone, $body, $options);
//         }
//     }

//     // ─── Default fallback message when something upstream fails ───────────────
//     private function sendFallbackMessage(Company $company, string $phone): void
//     {
//         $this->sendText(
//             $company,
//             $phone,
//             "Sorry, that option isn't available right now — please try again in a moment, or reply *menu* to start over."
//         );
//     }

//     // ─── Send an array of messages sequentially (text/image/video/document/audio/location) ─
//     private function sendMultipleMessages(Company $company, string $phone, array $messages): void
//     {
//         foreach ($messages as $i => $msg) {
//             if ($i > 0) {
//                 usleep(400000); // WhatsApp doesn't guarantee order without a delay
//             }

//             $type = $msg['type'] ?? 'text';

//             match ($type) {
//                 'text'     => $this->sendText($company, $phone, $msg['content'] ?? $msg['text'] ?? ''),
//                 'image'    => $this->sendSingleMedia($company, $phone, 'image',    $msg['url'], $msg['caption'] ?? null, null),
//                 'video'    => $this->sendSingleMedia($company, $phone, 'video',    $msg['url'], $msg['caption'] ?? null, null),
//                 'document' => $this->sendSingleMedia($company, $phone, 'document', $msg['url'], $msg['caption'] ?? null, $msg['filename'] ?? 'Document.pdf'),
//                 'audio'    => $this->sendAudio($company, $phone, $msg['url']),
//                 'location' => $this->dispatch($company, [
//                     'messaging_product' => 'whatsapp',
//                     'to'                => $phone,
//                     'type'              => 'location',
//                     'location'          => [
//                         'latitude'  => $msg['lat'],
//                         'longitude' => $msg['lng'],
//                         'name'      => $msg['name']    ?? '',
//                         'address'   => $msg['address'] ?? '',
//                     ],
//                 ]),
//                 default => null,
//             };
//         }
//     }

//     // ─── Auto-create lead from flow ───────────────────────────────────────────
//     private function autoCreateLead(Company $company, Contact $contact, FlowNode $node): void
//     {
//         $existing = $this->leadRepository->findByContact($contact->id, $company->id);
//         if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
//             Log::info("Lead already active for contact {$contact->id} — skipping auto-create");
//             return;
//         }

//         $dto = CreateLeadDTO::fromFlow(
//             contactId: $contact->id,
//             flowNodeId: $node->id,
//             category: $node->lead_category,
//         );

//         try {
//             $lead = $this->leadRepository->create($company->id, $dto);
//             Log::info("Auto-created lead {$lead->id} for contact {$contact->id} from flow node {$node->id}");
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

//     // ─── Extract readable content from message ────────────────────────────────
//     private function extractContent(InboundMessageDTO $dto): string
//     {
//         return match ($dto->type) {
//             'text'        => $dto->text ?? '',
//             'interactive' => $dto->replyTitle ?? $dto->replyId ?? '[interactive reply]',
//             'image'       => '[Image]' . ($dto->caption ? ': ' . $dto->caption : ''),
//             'audio'       => '[Voice note]',
//             'video'       => '[Video]',
//             'document'    => '[Document]',
//             'sticker'     => '[Sticker]',
//             'location'    => '[Location]',
//             default       => '[' . $dto->type . ']',
//         };
//     }

//     // ─── WhatsApp API helpers ─────────────────────────────────────────────────
//     private function sendText(Company $company, string $phone, string $text): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'text',
//             'text'              => ['body' => $text, 'preview_url' => false],
//         ]);
//     }

//     private function sendButton(Company $company, string $phone, string $body, $children): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => [
//                 'type' => 'button',
//                 'body' => ['text' => $body],
//                 'action' => [
//                     'buttons' => $children->map(fn($c) => [
//                         'type'  => 'reply',
//                         'reply' => [
//                             'id'    => mb_substr($c->reply_id, 0, 256),
//                             'title' => mb_substr($c->title, 0, 20),
//                         ],
//                     ])->values()->all(),
//                 ],
//             ],
//         ]);
//     }

//     // ── Button variant for dynamic (array-based) options ──────────────────
//     private function sendButtonFromArray(Company $company, string $phone, string $body, array $options): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => [
//                 'type' => 'button',
//                 'body' => ['text' => $body],
//                 'action' => [
//                     'buttons' => collect($options)->map(fn($o) => [
//                         'type'  => 'reply',
//                         'reply' => [
//                             'id'    => mb_substr($o['reply_id'], 0, 256),
//                             'title' => mb_substr($o['title'], 0, 20),
//                         ],
//                     ])->values()->all(),
//                 ],
//             ],
//         ]);
//     }

//     private function sendList(Company $company, string $phone, string $body, $children): void
//     {
//         $this->dispatch($company, [
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
//                             'id'          => mb_substr($c->reply_id, 0, 200),
//                             'title'       => mb_substr($c->title, 0, 24),
//                             'description' => mb_substr($c->message ?? '', 0, 72),
//                         ])->values()->all(),
//                     ]],
//                 ],
//             ],
//         ]);
//     }

//     // ── List variant for dynamic (array-based) options ─────────────────────
//     private function sendListFromArray(Company $company, string $phone, string $body, array $options): void
//     {
//         $this->dispatch($company, [
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
//                         'rows'  => collect($options)->map(fn($o) => [
//                             'id'          => mb_substr($o['reply_id'], 0, 200),
//                             'title'       => mb_substr($o['title'], 0, 24),
//                             'description' => mb_substr($o['description'] ?? $o['subtitle'] ?? '', 0, 72),
//                         ])->values()->all(),
//                     ]],
//                 ],
//             ],
//         ]);
//     }

//     // ── Button with optional media header (single node, no multi_messages) ─
//     private function sendButtonWithOptionalMedia(Company $company, string $phone, FlowNode $node, $children): void
//     {
//         $interactive = [
//             'type' => 'button',
//             'body' => ['text' => $node->message],
//             'action' => [
//                 'buttons' => $children->map(fn($c) => [
//                     'type'  => 'reply',
//                     'reply' => [
//                         'id'    => mb_substr($c->reply_id, 0, 256),
//                         'title' => mb_substr($c->title,    0, 20),
//                     ],
//                 ])->values()->all(),
//             ],
//         ];

//         if ($node->media_type && $node->media_url && in_array($node->media_type, ['image', 'video', 'document'])) {
//             $interactive['header'] = [
//                 'type'                         => strtoupper($node->media_type),
//                 strtolower($node->media_type)  => ['link' => $node->media_url],
//             ];
//         }

//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => $interactive,
//         ]);
//     }

//     // ── Send image / video / document ───────────────────────────────────────
//     private function sendSingleMedia(Company $company, string $phone, string $type, string $url, ?string $caption, ?string $filename): void
//     {
//         $payload = ['link' => $url];
//         if ($caption)  $payload['caption']  = $caption;
//         if ($filename) $payload['filename'] = $filename;

//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => $type,
//             $type               => $payload,
//         ]);
//     }

//     // ── Send audio ────────────────────────────────────────────────────────
//     private function sendAudio(Company $company, string $phone, string $url): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'audio',
//             'audio'             => ['link' => $url],
//         ]);
//     }

//     // ── Send location ─────────────────────────────────────────────────────
//     private function sendLocation(Company $company, string $phone, FlowNode $node): void
//     {
//         $this->dispatch($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'location',
//             'location'          => [
//                 'latitude'  => $node->location_lat,
//                 'longitude' => $node->location_lng,
//                 'name'      => $node->location_name    ?? '',
//                 'address'   => $node->location_address ?? '',
//             ],
//         ]);
//     }

//     // ─── POST to Meta Graph API (single dispatch path — no more duplicate) ────
//     private function dispatch(Company $company, array $payload): void
//     {
//         if (!$company->wa_phone_id || !$company->wa_access_token) {
//             Log::error("Company {$company->id} has no WA credentials — cannot send message");
//             return;
//         }

//         try {
//             $token = $company->wa_access_token;
//             try {
//                 $token = decrypt($token);
//             } catch (\Exception) { /* already plain */
//             }

//             $response = Http::withToken($token)
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
//                     'content'       => $this->extractOutboundContent($payload),
//                     'status'        => 'sent',
//                     'cost'          => 1,
//                 ]);

//                 Log::info("WA message sent: waId={$waId} to={$payload['to']} company={$company->id}");
//             } else {
//                 $error = $response->json('error.message') ?? 'Unknown error';
//                 $code  = $response->json('error.code');
//                 Log::error("WA send failed: [{$code}] {$error}", [
//                     'company'  => $company->id,
//                     'to'       => $payload['to'],
//                     'response' => $response->json(),
//                 ]);
//             }
//         } catch (\Exception $e) {
//             Log::error("WA dispatch exception: " . $e->getMessage(), ['company' => $company->id]);
//         }
//     }

//     private function extractOutboundContent(array $payload): string
//     {
//         return match ($payload['type']) {
//             'text'        => $payload['text']['body'] ?? '',
//             'image'       => '[Image]' . (!empty($payload['image']['caption']) ? ': ' . $payload['image']['caption'] : ''),
//             'video'       => '[Video]' . (!empty($payload['video']['caption']) ? ': ' . $payload['video']['caption'] : ''),
//             'document'    => '[Document]' . (!empty($payload['document']['filename']) ? ': ' . $payload['document']['filename'] : ''),
//             'audio'       => '[Audio message]',
//             'location'    => '[Location]' . (!empty($payload['location']['name']) ? ': ' . $payload['location']['name'] : ''),
//             'interactive' => match ($payload['interactive']['type'] ?? '') {
//                 'button' => $payload['interactive']['body']['text'] ?? '[button message]',
//                 'list'   => $payload['interactive']['body']['text'] ?? '[list message]',
//                 default  => '[interactive]',
//             },
//             default => '[' . $payload['type'] . ']',
//         };
//     }

//     private function isGreeting(?string $text): bool
//     {
//         if (!$text) return false;
//         return in_array(strtolower(trim($text)), self::GREETING_KEYWORDS);
//     }
// }
