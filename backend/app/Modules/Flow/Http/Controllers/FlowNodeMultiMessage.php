<?php

// // ════════════════════════════════════════════════════════════════════════════
// // 1. MIGRATION — add multi_messages column to flow_nodes
// // ════════════════════════════════════════════════════════════════════════════

// // php artisan make:migration add_multi_messages_to_flow_nodes
// // Schema::table('flow_nodes', function (Blueprint $table) {
// //     // Each node can send multiple messages in sequence
// //     // JSON array of message objects — sent one by one with small delay
// //     $table->json('multi_messages')->nullable()->after('message');
// //     // media for the main (first) message
// //     $table->string('media_type',   20)->nullable()->after('multi_messages'); // image|video|document|audio|location
// //     $table->string('media_url',   500)->nullable()->after('media_type');
// //     $table->string('media_id',    100)->nullable()->after('media_url');      // Meta media ID
// //     $table->string('media_caption',255)->nullable()->after('media_id');
// //     $table->string('media_filename',150)->nullable()->after('media_caption');
// //     $table->decimal('location_lat', 10, 7)->nullable()->after('media_filename');
// //     $table->decimal('location_lng', 10, 7)->nullable()->after('location_lat');
// //     $table->string('location_name',   150)->nullable()->after('location_lng');
// //     $table->string('location_address',255)->nullable()->after('location_name');
// // });

// // ════════════════════════════════════════════════════════════════════════════
// // multi_messages JSON structure
// // Each item in the array = one WhatsApp message sent sequentially
// // ════════════════════════════════════════════════════════════════════════════
// //
// // [
// //   { "type": "text",     "content": "Hello! Here are your company details 👋" },
// //   { "type": "image",    "url": "https://cdn.example.com/office.jpg",   "caption": "Our office in Kochi" },
// //   { "type": "video",    "url": "https://cdn.example.com/intro.mp4",    "caption": "Company intro video" },
// //   { "type": "image",    "url": "https://cdn.example.com/team1.jpg",    "caption": "Our team" },
// //   { "type": "image",    "url": "https://cdn.example.com/team2.jpg",    "caption": "Award winners 2024" },
// //   { "type": "audio",    "url": "https://cdn.example.com/message.ogg"  },
// //   { "type": "document", "url": "https://cdn.example.com/brochure.pdf", "filename": "Univexa-Brochure.pdf" },
// //   { "type": "location", "lat": 9.9312, "lng": 76.2673, "name": "Univexa HQ", "address": "MG Road, Kochi" }
// // ]


// // ════════════════════════════════════════════════════════════════════════════
// // 2. FlowNode MODEL — cast multi_messages
// // ════════════════════════════════════════════════════════════════════════════

// namespace App\Models;

// use Illuminate\Database\Eloquent\Model;

// class FlowNode extends Model
// {
//     protected $fillable = [
//         'company_id', 'flow_builder_id', 'parent_id',
//         'title', 'message', 'multi_messages',
//         'type', 'reply_id', 'lead_category', 'sort_order', 'is_active',
//         'media_type', 'media_url', 'media_id', 'media_caption',
//         'media_filename', 'location_lat', 'location_lng',
//         'location_name', 'location_address',
//     ];

//     protected $casts = [
//         'multi_messages' => 'array',
//         'is_active'      => 'boolean',
//         'sort_order'     => 'integer',
//         'location_lat'   => 'float',
//         'location_lng'   => 'float',
//     ];

//     public function company()  { return $this->belongsTo(\App\Models\Company::class); }
//     public function builder()  { return $this->belongsTo(FlowBuilder::class, 'flow_builder_id'); }
//     public function parent()   { return $this->belongsTo(FlowNode::class, 'parent_id'); }
//     public function children() { return $this->hasMany(FlowNode::class, 'parent_id')->orderBy('sort_order'); }

//     public function hasMultipleMessages(): bool
//     {
//         return !empty($this->multi_messages) && count($this->multi_messages) > 0;
//     }
// }


// // ════════════════════════════════════════════════════════════════════════════
// // 3. WebhookService — replace sendNodeResponse() with multi-message support
// // ════════════════════════════════════════════════════════════════════════════

// // In App\Modules\Webhook\Services\WebhookService

//     private function sendNodeResponse(Company $company, string $phone, FlowNode $node): void
//     {
//         $children = $node->children()->where('is_active', true)->orderBy('sort_order')->get();

//         // ── Multi-message mode ────────────────────────────────────────────
//         // If node has multi_messages array, send each one sequentially first
//         // then send the interactive options (buttons/list) as the LAST message
//         if ($node->hasMultipleMessages()) {
//             $this->sendMultipleMessages($company, $phone, $node->multi_messages);

//             // After all content messages, send interactive options if children exist
//             if ($children->isNotEmpty()) {
//                 usleep(500000); // 0.5s delay before options
//                 if ($node->type === 'button' && $children->count() <= 3) {
//                     $this->sendButton($company, $phone, '👇 Please select an option:', $children);
//                 } else {
//                     $this->sendList($company, $phone, '👇 Please select an option:', $children);
//                 }
//             }
//             return;
//         }

//         // ── Single message mode (existing behaviour) ──────────────────────
//         if ($node->media_type === 'audio' && $node->media_url) {
//             $this->sendAudio($company, $phone, $node->media_url);
//             if ($node->message) { usleep(300000); $this->sendText($company, $phone, $node->message); }
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
//                 $this->sendText($company, $phone, $node->message);
//             }
//             return;
//         }

//         if ($node->type === 'button' && $children->count() <= 3) {
//             $this->sendButtonWithOptionalMedia($company, $phone, $node, $children);
//         } else {
//             $this->sendList($company, $phone, $node->message, $children);
//         }
//     }

//     // ── Send array of messages sequentially with delays ───────────────────
//     private function sendMultipleMessages(Company $company, string $phone, array $messages): void
//     {
//         foreach ($messages as $i => $msg) {
//             // Small delay between messages so they arrive in order
//             // WhatsApp does not guarantee order without delays
//             if ($i > 0) {
//                 usleep(400000); // 400ms between messages
//             }

//             $type = $msg['type'] ?? 'text';

//             match ($type) {
//                 'text'     => $this->sendText($company, $phone, $msg['content'] ?? $msg['text'] ?? ''),
//                 'image'    => $this->sendSingleMedia($company, $phone, 'image',    $msg['url'], $msg['caption'] ?? null, null),
//                 'video'    => $this->sendSingleMedia($company, $phone, 'video',    $msg['url'], $msg['caption'] ?? null, null),
//                 'document' => $this->sendSingleMedia($company, $phone, 'document', $msg['url'], $msg['caption'] ?? null, $msg['filename'] ?? 'Document.pdf'),
//                 'audio'    => $this->sendAudio($company, $phone, $msg['url']),
//                 'location' => $this->dispatchRaw($company, [
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

//     // ── Send image / video / document ─────────────────────────────────────
//     private function sendSingleMedia(
//         Company $company, string $phone,
//         string $type, string $url,
//         ?string $caption, ?string $filename
//     ): void {
//         $mediaPayload = ['link' => $url];
//         if ($caption)  $mediaPayload['caption']  = $caption;
//         if ($filename) $mediaPayload['filename']  = $filename;

//         $this->dispatchRaw($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => $type,
//             $type               => $mediaPayload,
//         ]);
//     }

//     // ── Send audio ────────────────────────────────────────────────────────
//     private function sendAudio(Company $company, string $phone, string $url): void
//     {
//         $this->dispatchRaw($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'audio',
//             'audio'             => ['link' => $url],
//         ]);
//     }

//     // ── Send location ─────────────────────────────────────────────────────
//     private function sendLocation(Company $company, string $phone, FlowNode $node): void
//     {
//         $this->dispatchRaw($company, [
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

//     // ── Button with optional media header ─────────────────────────────────
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

//         if ($node->media_type && $node->media_url && in_array($node->media_type, ['image','video','document'])) {
//             $interactive['header'] = [
//                 'type'                => strtoupper($node->media_type),
//                 strtolower($node->media_type) => ['link' => $node->media_url],
//             ];
//         }

//         $this->dispatchRaw($company, [
//             'messaging_product' => 'whatsapp',
//             'to'                => $phone,
//             'type'              => 'interactive',
//             'interactive'       => $interactive,
//         ]);
//     }

//     // ── Raw dispatch (replaces the old dispatch() for clarity) ────────────
//     private function dispatchRaw(Company $company, array $payload): void
//     {
//         if (!$company->wa_phone_id || !$company->wa_access_token) return;
//         try {
//             $token = $company->wa_access_token;
//             try { $token = decrypt($token); } catch (\Exception) {}

//             $response = \Illuminate\Support\Facades\Http::withToken($token)
//                 ->timeout(10)
//                 ->post("https://graph.facebook.com/v21.0/{$company->wa_phone_id}/messages", $payload);

//             if ($response->successful()) {
//                 $waId = $response->json('messages.0.id');
//                 \App\Models\MessageLog::create([
//                     'company_id'    => $company->id,
//                     'wa_message_id' => $waId,
//                     'direction'     => 'outbound',
//                     'type'          => $payload['type'],
//                     'phone'         => $payload['to'],
//                     'content'       => $this->extractOutboundContent($payload),
//                     'status'        => 'sent',
//                     'cost'          => 1,
//                 ]);
//             } else {
//                 \Illuminate\Support\Facades\Log::error('WA send failed', [
//                     'company'  => $company->id,
//                     'code'     => $response->json('error.code'),
//                     'message'  => $response->json('error.message'),
//                 ]);
//             }
//         } catch (\Exception $e) {
//             \Illuminate\Support\Facades\Log::error('WA dispatch exception: ' . $e->getMessage());
//         }
//     }

//     private function extractOutboundContent(array $payload): string
//     {
//         return match($payload['type']) {
//             'text'        => $payload['text']['body']              ?? '',
//             'image'       => '[Image] '  . ($payload['image']['caption']    ?? ''),
//             'video'       => '[Video] '  . ($payload['video']['caption']    ?? ''),
//             'document'    => '[Document] ' . ($payload['document']['filename'] ?? ''),
//             'audio'       => '[Audio message]',
//             'location'    => '[Location] ' . ($payload['location']['name']  ?? ''),
//             'interactive' => $payload['interactive']['body']['text'] ?? '[interactive]',
//             default       => '[' . $payload['type'] . ']',
//         };
//     }

