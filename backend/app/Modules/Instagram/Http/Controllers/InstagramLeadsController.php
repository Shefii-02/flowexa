<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramConversation;
use App\Modules\Catalog\LeadIntake;
use App\Modules\Catalog\Support\ChannelLeadRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Leads" page of Instagram and of Messenger (same tables, ?channel=instagram|facebook): everyone who messaged, with what we know about them,
 * and whether they are already in the leads table. Staff move the ones that are not — with a phone number, because a lead needs one.
 */
class InstagramLeadsController extends Controller
{
    private function channel(Request $r): string
    {
        return $r->query('channel', $r->input('channel', 'instagram')) === 'facebook' ? 'facebook' : 'instagram';
    }

    private function base(string $channel)
    {
        $allowed = auth()->user()->allowedAccountIds('instagram_account');
        return InstagramConversation::where('company_id', auth()->user()->company_id)
            ->whereNotNull('last_inbound_at')                                   // somebody actually wrote to us
            ->whereHas('account', fn ($q) => $q->where('channel', $channel))
            ->when($allowed !== null, fn ($q) => $q->whereIn('instagram_account_id', $allowed));
    }

    /** GET /instagram/leads?channel=&tab=new|leads|all&search= */
    public function index(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        $tab = in_array($request->query('tab'), ['new', 'leads', 'all'], true) ? $request->query('tab') : 'new';
        $search = trim((string) $request->query('search'));

        $q = $this->base($channel)->when($search !== '', function ($w) use ($search) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $w->where(fn ($x) => $x->where('participant_username', 'like', $like)->orWhere('collected->name', 'like', $like)->orWhere('collected->phone', 'like', $like));
        });
        $counts = [
            'new' => (clone $q)->whereNull('lead_id')->count(),
            'leads' => (clone $q)->whereNotNull('lead_id')->count(),
            'all' => (clone $q)->count(),
        ];
        $page = $q->when($tab === 'new', fn ($x) => $x->whereNull('lead_id'))->when($tab === 'leads', fn ($x) => $x->whereNotNull('lead_id'))
            ->with('account:id,username,name')->orderByDesc('last_inbound_at')->paginate(25);

        $leads = ChannelLeadRows::leads($page->getCollection()->pluck('lead_id')->all());
        $page->getCollection()->transform(fn (InstagramConversation $c) => [
            'id' => $c->id, 'username' => $c->participant_username, 'name' => $c->collected['name'] ?? null, 'phone' => $c->collected['phone'] ?? null,
            'account' => $c->account?->username ? '@' . $c->account->username : $c->account?->name,
            'preview' => $c->last_message_preview, 'last_at' => $c->last_inbound_at?->toIso8601String(), 'unread' => (int) $c->unread_count,
            'lead' => $c->lead_id ? ($leads[$c->lead_id] ?? ['id' => $c->lead_id, 'stage' => 'new', 'assigned_to' => null]) : null,
        ]);

        return response()->json($page->toArray() + ['counts' => $counts, 'channel' => $channel]);
    }

    /** POST /instagram/leads/{id}/move { name?, phone } — put this person into the leads table. */
    public function move(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:40']]);
        $convo = $this->base($this->channel($request))->with('account')->findOrFail($id);
        if ($convo->lead_id) {
            return response()->json(['message' => 'Already in the leads table.', 'lead_id' => $convo->lead_id]);
        }
        $phone = ChannelLeadRows::cleanPhone($d['phone']);
        abort_if($phone === '', 422, 'Enter a valid phone number — a lead needs one.');

        $account = $convo->account;
        $name = trim((string) ($d['name'] ?? '')) ?: ($convo->collected['name'] ?? null) ?: $convo->participant_username;
        $result = app(LeadIntake::class)->capture(
            auth()->user()->company, ['name' => $name, 'phone' => $phone, 'source_detail' => 'moved from the ' . ($account->channel === 'facebook' ? 'Messenger' : 'Instagram') . ' Leads page'],
            $account->channel === 'facebook' ? 'facebook_dm' : 'instagram_dm', "ig:{$convo->id}",
            originType: 'instagram_account', originId: $account->id, originLabel: $account->username ? "@{$account->username}" : $account->name,
        );
        if (!empty($result['held'])) {
            return response()->json(['message' => 'Saved — it is waiting in Lead Review before it becomes a lead.', 'held' => true]);
        }
        if (!$result['contact']) {
            return response()->json(['message' => 'Could not save that contact.'], 422);
        }
        $convo->update([
            'contact_id' => $result['contact']->id, 'lead_id' => $result['lead']?->id, 'lead_created_at' => now(),
            'collected' => array_merge((array) ($convo->collected ?? []), array_filter(['name' => $name, 'phone' => $phone])),
        ]);
        return response()->json(['message' => 'Moved to leads.', 'lead_id' => $result['lead']?->id]);
    }
}
