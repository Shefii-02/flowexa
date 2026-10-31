<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SupportDepartment;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Modules\Support\Services\SupportTicketService as Svc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The company's side of support: raise a ticket (with its own priority and department), follow it, reply, close, rate. */
class SupportTicketController extends Controller
{
    public function __construct(private readonly Svc $svc) {}

    private function mine(int $id): SupportTicket
    {
        return SupportTicket::where('company_id', auth()->user()->company_id)->with(['department', 'creator:id,name', 'assignee:id,name'])->findOrFail($id);
    }

    public function meta(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        return response()->json([
            'departments' => SupportDepartment::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'description']),
            'priorities'  => collect(SupportTicket::PRIORITIES)->map(fn ($p) => ['value' => $p, 'response_hours' => SupportTicket::SLA_HOURS[$p]]),
            'counts'      => SupportTicket::where('company_id', $companyId)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = SupportTicket::where('company_id', auth()->user()->company_id)
            ->with(['department:id,name', 'creator:id,name', 'assignee:id,name'])
            ->when($request->status, fn ($q, $s) => $s === 'active' ? $q->whereNotIn('status', ['resolved', 'closed']) : $q->where('status', $s))
            ->when($request->priority, fn ($q, $p) => $q->where('priority', $p))
            ->when($request->department_id, fn ($q, $d) => $q->where('department_id', $d))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('subject', 'like', "%$s%")->orWhere('ticket_no', 'like', "%$s%")))
            ->orderByDesc('last_reply_at')->orderByDesc('id')
            ->paginate(20);

        $page->getCollection()->transform(fn ($t) => $this->svc->summary($t, false));
        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'subject'       => ['required', 'string', 'max:200'],
            'description'   => ['required', 'string', 'max:8000'],
            'priority'      => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'department_id' => ['required', Rule::exists('support_departments', 'id')->where('is_active', true)],
            'files'         => ['nullable', 'array', 'max:' . Svc::MAX_FILES],
            'files.*'       => Svc::FILE_RULES,
        ]);
        $user = auth()->user();

        $ticket = SupportTicket::create([
            'company_id'    => $user->company_id,
            'created_by'    => $user->id,
            'department_id' => $d['department_id'],
            'subject'       => $d['subject'],
            'description'   => $d['description'],
            'priority'      => $d['priority'],
            'status'        => 'open',
            'due_at'        => SupportTicket::dueAtFor($d['priority']),
            'last_reply_at' => now(),
            'last_reply_by' => 'company',
            // a department can route its tickets straight to a person
            'assigned_to'   => SupportDepartment::find($d['department_id'])?->default_assignee_id,
        ]);
        $ticket->update(['ticket_no' => 'TKT-' . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)]);
        $this->svc->addMessage($ticket, $user, 'company', $d['description'], false, $request->file('files', []));

        return response()->json(['message' => 'Ticket raised. Our team will reply here.', 'ticket' => $this->svc->summary($ticket->load(['department', 'creator:id,name', 'assignee:id,name']), false)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $t = $this->mine($id);
        return response()->json(['ticket' => $this->svc->summary($t, false), 'messages' => $this->svc->thread($t, false)]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $t = $this->mine($id);
        if ($t->status === 'closed') {
            return response()->json(['message' => 'This ticket is closed. Reopen it to reply.'], 422);
        }
        $d = $request->validate([
            'body'    => ['required', 'string', 'max:8000'],
            'files'   => ['nullable', 'array', 'max:' . Svc::MAX_FILES],
            'files.*' => Svc::FILE_RULES,
        ]);

        $this->svc->addMessage($t, auth()->user(), 'company', $d['body'], false, $request->file('files', []));
        $t->update([
            'last_reply_at' => now(), 'last_reply_by' => 'company',
            // a customer reply puts a waiting / resolved ticket back in the queue
            'status'        => in_array($t->status, ['waiting_customer', 'resolved'], true) ? 'open' : $t->status,
            'resolved_at'   => $t->status === 'resolved' ? null : $t->resolved_at,
        ]);
        return response()->json(['message' => 'Reply sent.']);
    }

    public function close(int $id): JsonResponse
    {
        $t = $this->mine($id);
        if ($t->status !== 'closed') {
            $t->update(['status' => 'closed', 'closed_at' => now()]);
            $this->svc->event($t, auth()->user(), auth()->user()->name . ' closed this ticket.');
        }
        return response()->json(['message' => 'Ticket closed.']);
    }

    public function reopen(int $id): JsonResponse
    {
        $t = $this->mine($id);
        if (in_array($t->status, ['resolved', 'closed'], true)) {
            $t->update(['status' => 'open', 'resolved_at' => null, 'closed_at' => null, 'last_reply_at' => now(), 'last_reply_by' => 'company']);
            $this->svc->event($t, auth()->user(), auth()->user()->name . ' reopened this ticket.');
        }
        return response()->json(['message' => 'Ticket reopened.']);
    }

    public function rate(Request $request, int $id): JsonResponse
    {
        $t = $this->mine($id);
        abort_unless(in_array($t->status, ['resolved', 'closed'], true), 422, 'You can rate a ticket once it is resolved.');
        $d = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'feedback' => ['nullable', 'string', 'max:500']]);
        $t->update(['rating' => $d['rating'], 'rating_feedback' => $d['feedback'] ?? null]);
        return response()->json(['message' => 'Thanks for your feedback.']);
    }

    public function attachment(int $ticketId, int $attachmentId)
    {
        $t = $this->mine($ticketId);
        $a = SupportTicketAttachment::where('ticket_id', $t->id)->whereHas('message', fn ($q) => $q->where('is_internal', false))->findOrFail($attachmentId);
        return $this->svc->download($a);
    }
}
