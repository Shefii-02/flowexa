<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

/** Activity view: the webhook deliveries we received for this workspace and what happened to each. */
class InstagramActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allowed = auth()->user()->allowedAccountIds('instagram_account');
        $page = DB::table('instagram_webhook_events as e')
            ->leftJoin('instagram_accounts as a', 'a.id', '=', 'e.instagram_account_id')
            ->where('e.company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('e.instagram_account_id', $allowed))
            ->when($request->query('channel') === 'facebook',
                fn ($q) => $q->where('a.channel', 'facebook'),
                fn ($q) => $q->where(fn ($q) => $q->where('a.channel', 'instagram')->orWhereNull('a.channel')))
            ->when($request->query('status'), fn ($q, $v) => $q->where('e.status', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('e.kind', $v))
            ->when($request->query('account_id'), fn ($q, $v) => $q->where('e.instagram_account_id', $v))
            ->orderByDesc('e.id')
            ->select('e.id', 'e.kind', 'e.field', 'e.status', 'e.note', 'e.payload', 'e.created_at', 'e.processed_at', 'a.username')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $page->getCollection()->transform(function ($r) {
            $p = json_decode($r->payload, true) ?: [];
            $r->summary = mb_substr((string) ($p['text'] ?? $p['message']['text'] ?? $p['postback']['title'] ?? ''), 0, 140);
            $r->from = $p['from']['username'] ?? $p['from']['id'] ?? $p['sender']['id'] ?? null;
            $r->payload = $p;
            return $r;
        });

        return response()->json($page);
    }
}
