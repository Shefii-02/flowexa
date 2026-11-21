<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * When did WhatsApp confirm each message? The gateway knows a message's status but not the moment it changed, so Laravel keeps those moments
 * (written by the `message.ack` webhook) and the Webhook Logs page asks for them here, together with the wallet charge a message caused.
 */
class MessageDeliveryController extends Controller
{
    /** POST waha/message-delivery  { ids: [wa message ids] } → { data: { <id>: { status, sent_at, delivered_at, read_at, credits, billed_as, charge } } } */
    public function show(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|max:200', 'ids.*' => 'string|max:191'])['ids'];
        $companyId = auth()->user()->company_id;

        $logs = MessageLog::where('company_id', $companyId)->where('channel', 'wa_chat')->where('direction', 'outbound')->whereIn('wa_message_id', $ids)
            ->get(['wa_message_id', 'status', 'created_at', 'delivered_at', 'read_at', 'failure_reason'])->keyBy('wa_message_id');
        $charges = DB::table('wa_chat_charges')->where('company_id', $companyId)->whereIn('wa_message_id', $ids)->get(['wa_message_id', 'source', 'credits', 'status'])->keyBy('wa_message_id');

        $out = [];
        foreach ($ids as $id) {
            $l = $logs[$id] ?? null; $c = $charges[$id] ?? null;
            if (!$l && !$c) { continue; }
            $out[$id] = [
                'status'       => $l?->status,
                'sent_at'      => $l?->created_at?->toIso8601String(),
                'delivered_at' => $l?->delivered_at?->toIso8601String(),
                'read_at'      => $l?->read_at?->toIso8601String(),
                'failure'      => $l?->failure_reason,
                // what this message cost: only campaign / API / test messages are billed; normal chat has no charge row
                'billed_as'    => $c?->source,
                'credits'      => $c && $c->status === 'charged' ? (int) $c->credits : 0,
                'charge'       => $c?->status,      // pending | charged | unbilled
            ];
        }
        return response()->json(['data' => $out]);
    }
}
