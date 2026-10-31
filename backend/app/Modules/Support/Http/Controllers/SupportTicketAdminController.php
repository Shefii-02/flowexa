<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SupportDepartment;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use App\Modules\Support\Services\SupportTicketService as Svc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The platform's side of support (superadmin + platform staff): triage by priority, assign, reply, resolve. */
class SupportTicketAdminController extends Controller
{
    public function __construct(private readonly Svc $svc) {}

    private const PRIORITY_ORDER = "CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END";

    private function agents()
    {
        return User::whereHas('role', fn ($r) => $r->whereIn('name', ['superadmin', 'superadmin_staff']))->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function find(int $id): SupportTicket
    {
        return SupportTicket::with(['company:id,name', 'department', 'creator:id,name,email', 'assignee:id,name'])->findOrFail($id);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'departments' => SupportDepartment::orderBy('sort_order')->get(['id', 'name', 'is_active']),
            'agents'      => $this->agents(),
            'priorities'  => SupportTicket::PRIORITIES,
            'statuses'    => SupportTicket::STATUSES,
        ]);
    }

    public function stats(): JsonResponse
    {
        $open = SupportTicket::whereNotIn('status', ['resolved', 'closed']);
        $since = now()->subDays(30);
        $avg = fn (string $col, string $from) => round((float) (SupportTicket::whereNotNull($col)->where('created_at', '>=', $since)
            ->selectRaw("AVG(TIMESTAMPDIFF(MINUTE, {$from}, {$col})) / 60 as h")->value('h') ?? 0), 1);

        return response()->json([
            'by_status'          => SupportTicket::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'open_by_priority'   => (clone $open)->selectRaw('priority, count(*) c')->groupBy('priority')->pluck('c', 'priority'),
            'unassigned'         => (clone $open)->whereNull('assigned_to')->count(),
            'mine'               => (clone $open)->where('assigned_to', auth()->id())->count(),
            'overdue'            => (clone $open)->whereNull('first_response_at')->where('due_at', '<', now())->count(),
            'avg_first_response_hours' => $avg('first_response_at', 'created_at'),
            'avg_resolution_hours'     => $avg('resolved_at', 'created_at'),
            'avg_rating'         => round((float) SupportTicket::whereNotNull('rating')->avg('rating'), 2),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $assigned = $request->assigned;
        $page = SupportTicket::with(['company:id,name', 'department:id,name', 'creator:id,name', 'assignee:id,name'])
            ->when($request->status, fn ($q, $s) => $s === 'active' ? $q->whereNotIn('status', ['resolved', 'closed']) : $q->where('status', $s))
            ->when($request->priority, fn ($q, $p) => $q->where('priority', $p))
            ->when($request->department_id, fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->company_id, fn ($q, $c) => $q->where('company_id', $c))
            ->when($assigned === 'me', fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when($assigned === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when(is_numeric($assigned), fn ($q) => $q->where('assigned_to', (int) $assigned))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereNull('first_response_at')->where('due_at', '<', now())->whereNotIn('status', ['resolved', 'closed']))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('subject', 'like', "%$s%")->orWhere('ticket_no', 'like', "%$s%")
                ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%$s%"))))
            // finished tickets sink; among the rest the company's chosen priority decides, then who has waited longest
            ->orderByRaw("CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END")
            ->orderByRaw(self::PRIORITY_ORDER)
            ->orderBy('due_at')
            ->paginate(20);

        $page->getCollection()->transform(fn ($t) => $this->svc->summary($t, true));
        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        $t = $this->find($id);
        return response()->json(['ticket' => $this->svc->summary($t, true) + ['requester_email' => $t->creator?->email], 'messages' => $this->svc->thread($t, true)]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $t = $this->find($id);
        $d = $request->validate([
            'body'        => ['required', 'string', 'max:8000'],
            'is_internal' => ['nullable', 'boolean'],
            'status'      => ['nullable', Rule::in(['open', 'in_progress', 'waiting_customer', 'resolved'])],
            'files'       => ['nullable', 'array', 'max:' . Svc::MAX_FILES],
            'files.*'     => Svc::FILE_RULES,
        ]);
        $internal = (bool) ($d['is_internal'] ?? false);
        $user = auth()->user();

        $this->svc->addMessage($t, $user, 'support', $d['body'], $internal, $request->file('files', []));
        if ($internal) {
            return response()->json(['message' => 'Internal note added.']);
        }

        $updates = ['last_reply_at' => now(), 'last_reply_by' => 'support'];
        if (!$t->first_response_at) $updates['first_response_at'] = now();
        if (!$t->assigned_to)       $updates['assigned_to'] = $user->id;           // whoever picks it up owns it
        // an answered ticket moves on: default is "waiting for the customer"; staff can choose another state
        $newStatus = $d['status'] ?? (in_array($t->status, ['open', 'in_progress'], true) ? 'waiting_customer' : $t->status);
        if ($newStatus !== $t->status) {
            $updates['status'] = $newStatus;
            if ($newStatus === 'resolved') $updates['resolved_at'] = now();
        }
        $t->update($updates);

        return response()->json(['message' => 'Reply sent.']);
    }

    /** Status, priority, department and assignee — each change leaves a line in the thread. */
    public function update(Request $request, int $id): JsonResponse
    {
        $t = $this->find($id);
        $d = $request->validate([
            'status'        => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority'      => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'department_id' => ['sometimes', 'exists:support_departments,id'],
            'assigned_to'   => ['sometimes', 'nullable', Rule::exists('users', 'id')],
        ]);
        $user = auth()->user();
        $by = $user->name;
        $label = fn ($v) => ucfirst(str_replace('_', ' ', (string) $v));
        $updates = [];

        if (isset($d['status']) && $d['status'] !== $t->status) {
            $updates['status'] = $d['status'];
            $updates['resolved_at'] = $d['status'] === 'resolved' ? now() : (in_array($d['status'], ['open', 'in_progress', 'waiting_customer'], true) ? null : $t->resolved_at);
            $updates['closed_at']   = $d['status'] === 'closed' ? now() : null;
            $this->svc->event($t, $user, "{$by} changed the status to {$label($d['status'])}.");
        }
        if (isset($d['priority']) && $d['priority'] !== $t->priority) {
            $updates['priority'] = $d['priority'];
            $updates['due_at']   = $t->first_response_at ? $t->due_at : SupportTicket::dueAtFor($d['priority'], $t->created_at);
            $this->svc->event($t, $user, "{$by} changed the priority from {$label($t->priority)} to {$label($d['priority'])}.");
        }
        if (isset($d['department_id']) && (int) $d['department_id'] !== (int) $t->department_id) {
            $updates['department_id'] = $d['department_id'];
            $this->svc->event($t, $user, "{$by} moved this ticket to " . SupportDepartment::find($d['department_id'])->name . '.');
        }
        if (array_key_exists('assigned_to', $d) && (int) $d['assigned_to'] !== (int) $t->assigned_to) {
            $agent = $d['assigned_to'] ? $this->agents()->firstWhere('id', (int) $d['assigned_to']) : null;
            abort_if($d['assigned_to'] && !$agent, 422, 'Tickets can only be assigned to platform staff.');
            $updates['assigned_to'] = $agent?->id;
            $this->svc->event($t, $user, $agent ? "{$by} assigned this ticket to {$agent->name}." : "{$by} unassigned this ticket.", true);
        }

        if ($updates) $t->update($updates);
        return response()->json(['message' => 'Ticket updated.', 'ticket' => $this->svc->summary($this->find($id), true)]);
    }

    public function destroy(int $id): JsonResponse
    {
        SupportTicket::findOrFail($id)->delete();
        return response()->json(['message' => 'Ticket deleted.']);
    }

    public function attachment(int $ticketId, int $attachmentId)
    {
        return $this->svc->download(SupportTicketAttachment::where('ticket_id', $ticketId)->findOrFail($attachmentId));
    }

    // ── Departments ───────────────────────────────────────────────────────────

    public function departments(): JsonResponse
    {
        return response()->json(['departments' => SupportDepartment::withCount('tickets')->with('defaultAssignee:id,name')->orderBy('sort_order')->get()]);
    }

    private function deptRules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:100'],
            'description'         => ['nullable', 'string', 'max:255'],
            'default_assignee_id' => ['nullable', 'exists:users,id'],
            'is_active'           => ['nullable', 'boolean'],
            'sort_order'          => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        return response()->json(SupportDepartment::create($request->validate($this->deptRules())), 201);
    }

    public function updateDepartment(Request $request, int $id): JsonResponse
    {
        $dept = SupportDepartment::findOrFail($id);
        $dept->update($request->validate($this->deptRules()));
        return response()->json($dept->fresh());
    }

    public function destroyDepartment(int $id): JsonResponse
    {
        $dept = SupportDepartment::withCount('tickets')->findOrFail($id);
        if ($dept->tickets_count > 0) {
            $dept->update(['is_active' => false]);
            return response()->json(['message' => 'This department has tickets, so it was switched off instead of deleted.']);
        }
        $dept->delete();
        return response()->json(['message' => 'Department deleted.']);
    }
}
