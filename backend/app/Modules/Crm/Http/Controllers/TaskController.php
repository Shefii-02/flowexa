<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use Illuminate\Http\JsonResponse;
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
            ->with(['contact:id,name,phone', 'assignee:id,name', 'creator:id,name', 'deal:id,title'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
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
        $data['company_id'] = $this->companyId();
        $data['created_by'] = auth()->id();

        return response()->json(['data' => CrmTask::create($data)->load('contact:id,name,phone', 'assignee:id,name', 'creator:id,name')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $task = $this->scoped()->findOrFail($id);
        $data = $this->validated($request, isUpdate: true);
        if (!$this->canManage($task)) {
            // the assignee may only move the status along
            $data = array_intersect_key($data, ['status' => 1]);
        }
        if (isset($data['status'])) {
            $data['completed_at'] = $data['status'] === 'done' ? ($task->completed_at ?? now()) : null;
        }
        $task->update($data);

        return response()->json(['data' => $task->fresh()->load('contact:id,name,phone', 'assignee:id,name', 'creator:id,name')]);
    }

    /** POST /crm/tasks/{id}/toggle — flip open ⇄ done. */
    public function toggle(int $id): JsonResponse
    {
        $task = $this->scoped()->findOrFail($id);
        $done = $task->status !== 'done';
        $task->update([
            'status'       => $done ? 'done' : 'open',
            'completed_at' => $done ? now() : null,
        ]);

        return response()->json(['data' => $task->fresh()->load('contact:id,name,phone', 'assignee:id,name', 'creator:id,name')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $task = $this->scoped()->findOrFail($id);
        abort_unless($this->canManage($task), 403, 'Only the person who assigned this task, or an admin, can delete it.');
        $task->delete();

        return response()->json(['message' => 'Deleted.']);
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
        ]);
    }
}
