<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{InstagramAccount, InstagramFlow};
use App\Modules\Instagram\Flow\FlowValidator;
use Illuminate\Http\{JsonResponse, Request};

class InstagramFlowController extends Controller
{
    public function __construct(private readonly FlowValidator $validator) {}

    public function index(Request $request): JsonResponse
    {
        $allowed = auth()->user()->allowedAccountIds('instagram_account');

        $flows = InstagramFlow::where('company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('instagram_account_id', $allowed))
            ->when($request->account_id, fn ($q, $id) => $q->where('instagram_account_id', $id))
            ->whereHas('account', fn ($q) => $q->where('channel', $request->query('channel', 'instagram')))
            ->withCount(['runs', 'runs as waiting_runs_count' => fn ($q) => $q->where('status', 'waiting')])
            ->orderByDesc('priority')->orderByDesc('updated_at')
            ->get(['id', 'instagram_account_id', 'name', 'trigger_type', 'is_active', 'priority', 'triggered_count', 'last_triggered_at', 'updated_at']);
        return response()->json(['flows' => $flows]);
    }

    public function show(int $id): JsonResponse
    {
        $flow = $this->find($id);
        return response()->json(['flow' => $flow, 'errors' => $this->problems($flow)]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'instagram_account_id' => ['required', 'integer', 'exists:instagram_accounts,id'],
            'name'                 => ['required', 'string', 'max:150'],
            'nodes'                => ['sometimes', 'array'],
            'edges'                => ['sometimes', 'array'],
            'priority'             => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);
        InstagramAccount::where('id', $d['instagram_account_id'])->where('company_id', auth()->user()->company_id)->firstOrFail();

        $nodes = $d['nodes'] ?? [['id' => 'trigger', 'type' => 'trigger_comment', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['media_scope' => 'all', 'keywords' => [], 'match_type' => 'any', 'reply_once_per_user' => true]]];
        $this->assertStructure($nodes, $d['edges'] ?? []);

        $flow = InstagramFlow::create([
            'company_id' => auth()->user()->company_id,
            'instagram_account_id' => $d['instagram_account_id'],
            'name' => $d['name'], 'priority' => $d['priority'] ?? 0,
            'nodes' => $nodes, 'edges' => $d['edges'] ?? [],
            'trigger_type' => $this->triggerType($nodes), 'is_active' => false,
        ]);
        return response()->json(['message' => 'Flow created.', 'flow' => $flow, 'errors' => $this->problems($flow)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $flow = $this->find($id);
        $d = $request->validate([
            'name'      => ['sometimes', 'string', 'max:150'],
            'nodes'     => ['sometimes', 'array'],
            'edges'     => ['sometimes', 'array'],
            'priority'  => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $nodes = $d['nodes'] ?? $flow->nodes;
        $edges = $d['edges'] ?? $flow->edges;
        $this->assertStructure($nodes, $edges);

        $flow->fill(array_merge($d, ['nodes' => $nodes, 'edges' => $edges, 'trigger_type' => $this->triggerType($nodes)]));
        if ($flow->is_active && ($errors = $this->problems($flow))) {
            // Edits that break an active flow switch it off rather than leaving a half-working bot live.
            $flow->is_active = false;
        }
        $flow->save();
        return response()->json(['message' => 'Flow saved.', 'flow' => $flow->fresh(), 'errors' => $this->problems($flow)]);
    }

    public function toggle(int $id): JsonResponse
    {
        $flow = $this->find($id);
        if (!$flow->is_active && ($errors = $this->problems($flow))) {
            return response()->json(['message' => 'Fix the flow before activating it.', 'errors' => $errors], 422);
        }
        $flow->update(['is_active' => !$flow->is_active]);
        return response()->json(['flow' => $flow->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Flow deleted.']);
    }

    /** Recent runs — who went through the flow and where they are / why they stopped. */
    public function runs(int $id): JsonResponse
    {
        $flow = $this->find($id);
        $runs = $flow->runs()->with('conversation:id,participant_username,follows_business')->latest('id')->limit(50)
            ->get(['id', 'instagram_conversation_id', 'igsid', 'status', 'current_node', 'wait_kind', 'error', 'created_at', 'finished_at']);
        return response()->json(['runs' => $runs]);
    }

    private function problems(InstagramFlow $flow): array
    {
        $nodes = $flow->nodes ?? [];
        $edges = $flow->edges ?? [];
        $errors = $this->validator->structure($nodes, $edges);
        if ($errors) {
            return $errors;
        }
        $errors = $this->validator->semantics($nodes, $edges);

        $trigger = $flow->triggerNode();
        if (($trigger['type'] ?? null) === 'trigger_dm' && !array_filter($trigger['data']['keywords'] ?? [])) {
            $errors[] = 'A DM trigger needs at least one keyword, otherwise every message would start the flow.';
        }
        if (!$flow->nextNodeId($trigger['id'] ?? '')) {
            $errors[] = 'Connect the trigger to a first step.';
        }
        // Messenger can't tell whether someone follows/likes the Page, so the follower check isn't available there.
        if ($flow->account?->channel === 'facebook' && collect($nodes)->contains(fn ($n) => ($n['type'] ?? '') === 'condition_follow')) {
            $errors[] = 'The "Follows us?" check is not available on Facebook Messenger. Remove it.';
        }
        return array_values(array_unique($errors));
    }

    private function assertStructure(array $nodes, array $edges): void
    {
        if ($errors = $this->validator->structure($nodes, $edges)) {
            abort(response()->json(['message' => $errors[0], 'errors' => $errors], 422));
        }
    }

    private function triggerType(array $nodes): string
    {
        foreach ($nodes as $n) {
            if (($n['type'] ?? '') === 'trigger_dm') return 'dm';
        }
        return 'comment';
    }

    private function find(int $id): InstagramFlow
    {
        $allowed = auth()->user()->allowedAccountIds('instagram_account');
        return InstagramFlow::where('company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('instagram_account_id', $allowed))
            ->findOrFail($id);
    }
}
