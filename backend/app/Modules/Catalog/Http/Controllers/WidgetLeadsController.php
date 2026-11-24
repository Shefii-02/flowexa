<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WidgetConversation;
use App\Modules\Catalog\LeadIntake;
use App\Modules\Catalog\Support\ChannelLeadRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The website widget's "Leads" page: visitors who left their details, and whether they are already in the leads table. */
class WidgetLeadsController extends Controller
{
    private function base()
    {
        return WidgetConversation::where('company_id', auth()->user()->company_id)
            ->where(fn ($q) => $q->whereNotNull('visitor_phone')->orWhereNotNull('visitor_name'))
            ->whereNull('duplicate_of_id');
    }

    /** GET /widgets/live/leads?tab=new|leads|all&search= */
    public function index(Request $request): JsonResponse
    {
        $tab = in_array($request->query('tab'), ['new', 'leads', 'all'], true) ? $request->query('tab') : 'new';
        $search = trim((string) $request->query('search'));
        $q = $this->base()->when($search !== '', function ($w) use ($search) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $w->where(fn ($x) => $x->where('visitor_name', 'like', $like)->orWhere('visitor_phone', 'like', $like));
        });
        $counts = ['new' => (clone $q)->whereNull('lead_id')->count(), 'leads' => (clone $q)->whereNotNull('lead_id')->count(), 'all' => (clone $q)->count()];
        $page = $q->when($tab === 'new', fn ($x) => $x->whereNull('lead_id'))->when($tab === 'leads', fn ($x) => $x->whereNotNull('lead_id'))
            ->with('widget:id,name')->orderByDesc('last_message_at')->orderByDesc('id')->paginate(25);

        $leads = ChannelLeadRows::leads($page->getCollection()->pluck('lead_id')->all());
        $page->getCollection()->transform(fn (WidgetConversation $c) => [
            'id' => $c->id, 'name' => $c->visitor_name, 'phone' => $c->visitor_phone, 'widget' => $c->widget?->name, 'page_url' => $c->page_url,
            'last_at' => $c->last_message_at?->toIso8601String(), 'live_status' => $c->live_status, 'case_status' => $c->case_status,
            'lead' => $c->lead_id ? ($leads[$c->lead_id] ?? ['id' => $c->lead_id, 'stage' => 'new', 'assigned_to' => null]) : null,
        ]);
        return response()->json($page->toArray() + ['counts' => $counts]);
    }

    /** POST /widgets/live/leads/{id}/move { name?, phone? } — put this visitor into the leads table. */
    public function move(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:40']]);
        $convo = $this->base()->with('widget')->findOrFail($id);
        if ($convo->lead_id) {
            return response()->json(['message' => 'Already in the leads table.', 'lead_id' => $convo->lead_id]);
        }
        $phone = ChannelLeadRows::cleanPhone($d['phone'] ?? $convo->visitor_phone);
        abort_if($phone === '', 422, 'Enter a valid phone number — a lead needs one.');
        $name = trim((string) ($d['name'] ?? '')) ?: $convo->visitor_name;

        $result = app(LeadIntake::class)->capture(
            auth()->user()->company, ['name' => $name, 'phone' => $phone, 'source_detail' => 'moved from the website widget Leads page'],
            'website_widget', "widget:{$convo->id}", originType: 'widget', originId: $convo->chat_widget_id, originLabel: $convo->widget?->name,
        );
        if (!empty($result['held'])) {
            return response()->json(['message' => 'Saved — it is waiting in Lead Review before it becomes a lead.', 'held' => true]);
        }
        if (!$result['contact']) {
            return response()->json(['message' => 'Could not save that contact.'], 422);
        }
        $convo->update(array_filter(['contact_id' => $result['contact']->id, 'lead_id' => $result['lead']?->id, 'visitor_name' => $name, 'visitor_phone' => $convo->visitor_phone ?: $phone]));
        \App\Modules\Catalog\WidgetLeadCategories::sync($convo->fresh());   // categories the visitor collected while tapping the flow
        return response()->json(['message' => 'Moved to leads.', 'lead_id' => $result['lead']?->id]);
    }
}
