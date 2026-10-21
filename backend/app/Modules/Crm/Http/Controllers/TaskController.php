<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    /** Admin / owner / team lead (the "parent" staff) monitor every task in the company. */
    private function isMonitor(): bool
    {
        $u = auth()->user();
        return $u->isSuperAdmin() || $u->isOwner() || in_array($u->role?->name, ['admin', 'team_lead'], true);
    }

    /** Company tasks; everyone else only sees tasks assigned to them or created by them. */
    private function scoped()
    {
        return CrmTask::where('company_id', $this->companyId())
            ->when(!$this->isMonitor(), fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', auth()->id())->orWhere('created_by', auth()->id())));
    }

    /** Full edit/delete: monitors and whoever created the task. The assignee can only update progress (see update/toggle). */
    private function canManage(CrmTask $t): bool
    {
        return $this->isMonitor() || (int) $t->created_by === (int) auth()->id();
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->scoped()
            ->with(['contact:id,name,phone', 'assignee:id,name', 'creator:id,name', 'deal:id,title', 'lead:id,contact_id,stage', 'lead.contact:id,name,phone'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            // tabs: todo = to-do list items, scheduled = dated calls/emails/chats/meetings, followup = follow-ups
            ->when($request->query('tab') === 'todo', fn ($q) => $q->where('type', 'todo'))
            ->when($request->query('tab') === 'scheduled', fn ($q) => $q->whereIn('type', ['call', 'email', 'whatsapp', 'meeting']))
            ->when($request->query('tab') === 'followup', fn ($q) => $q->where('type', 'followup'))
            ->when($request->query('exclude_type'), fn ($q, $t) => $q->where('type', '!=', $t))
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', $request->integer('lead_id')))
            ->when($request->boolean('by_me'), fn ($q) => $q->where('created_by', auth()->id()))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('deal_id'), fn ($q) => $q->where('deal_id', $request->integer('deal_id')))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $tasks, 'monitor' => $this->isMonitor()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $sync = (bool) ($data['sync_todo'] ?? false);
        unset($data['sync_todo']);
        $data['company_id'] = $this->companyId();
        $data['created_by'] = auth()->id();

        // a lead-based follow-up carries the lead's contact so it shows who to call
        if (!empty($data['lead_id']) && empty($data['contact_id'])) {
            $data['contact_id'] = \App\Models\Lead::where('company_id', $this->companyId())->whereKey($data['lead_id'])->value('contact_id');
        }

        $task = DB::transaction(function () use ($data, $sync) {
            $task = CrmTask::create($data);
            // "Also add to my to-do list": a linked to-do copy for whoever owns the follow-up; completing either completes both
            if ($sync && ($data['type'] ?? null) === 'followup') {
                $todo = CrmTask::create(array_merge($data, [
                    'type' => 'todo', 'assigned_to' => $data['assigned_to'] ?? auth()->id(), 'synced_task_id' => $task->id,
                ]));
                $task->update(['synced_task_id' => $todo->id]);
            }
            return $task;
        });

        return response()->json(['data' => $task->fresh()->load($this->with())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $task = $this->scoped()->findOrFail($id);
        $data = $this->validated($request, isUpdate: true);
        if (!$this->canManage($task)) {
            // the assignee may only move the status along
            $data = array_intersect_key($data, ['status' => 1]);
        }
        unset($data['sync_todo']);
        if (isset($data['status'])) {
            $data['completed_at'] = $data['status'] === 'done' ? ($task->completed_at ?? now()) : null;
        }
        $task->update($data);
        $this->mirror($task, array_intersect_key($data, array_flip(['status', 'completed_at', 'title', 'due_at'])));

        return response()->json(['data' => $task->fresh()->load('contact:id,name,phone', 'assignee:id,name', 'creator:id,name')]);
    }

    /** POST /crm/tasks/{id}/toggle — flip open ⇄ done. */
    public function toggle(int $id): JsonResponse
    {
        $task = $this->scoped()->findOrFail($id);
        $done = $task->status !== 'done';
        $patch = ['status' => $done ? 'done' : 'open', 'completed_at' => $done ? now() : null];
        $task->update($patch);
        $this->mirror($task, $patch);

        return response()->json(['data' => $task->fresh()->load('contact:id,name,phone', 'assignee:id,name', 'creator:id,name')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $task = $this->scoped()->findOrFail($id);
        abort_unless($this->canManage($task), 403, 'Only the person who assigned this task, or an admin, can delete it.');
        if ($task->synced_task_id) { CrmTask::where('company_id', $this->companyId())->whereKey($task->synced_task_id)->delete(); }
        $task->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** Keep the paired follow-up ⇄ to-do copy in step. */
    private function mirror(CrmTask $task, array $patch): void
    {
        if ($task->synced_task_id && $patch) {
            CrmTask::where('company_id', $this->companyId())->whereKey($task->synced_task_id)->update($patch);
        }
    }

    /** @return list<string> */
    private function with(): array
    {
        return ['contact:id,name,phone', 'assignee:id,name', 'creator:id,name', 'lead:id,contact_id,stage', 'lead.contact:id,name,phone'];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'title'       => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'type'        => ['nullable', Rule::in(CrmTask::TYPES)],
            'priority'    => ['nullable', 'in:low,medium,high'],
            'status'      => ['nullable', 'in:open,done,cancelled'],
            'due_at'      => ['nullable', 'date'],
            'contact_id'  => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $this->companyId())],
            'deal_id'     => ['nullable', 'integer', Rule::exists('crm_deals', 'id')->where('company_id', $this->companyId())],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $this->companyId())],
            'lead_id'     => ['nullable', 'integer', Rule::exists('leads', 'id')->where('company_id', $this->companyId())],
            'sync_todo'   => ['nullable', 'boolean'],
        ]);
    }
}
