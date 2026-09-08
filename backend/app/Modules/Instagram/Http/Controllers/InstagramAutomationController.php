<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{InstagramAccount, InstagramAutomation};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class InstagramAutomationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = InstagramAutomation::whereHas('account', fn ($q) => $q->where('company_id', auth()->user()->company_id))
            ->when($request->account_id, fn ($q, $id) => $q->where('instagram_account_id', $id))
            ->orderByDesc('priority')->orderByDesc('updated_at')
            ->get();
        return response()->json(['automations' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request, true);
        $this->assertAccount($d['instagram_account_id']);

        $rule = InstagramAutomation::create(array_merge($d, ['company_id' => auth()->user()->company_id]));
        return response()->json(['message' => 'Automation created.', 'automation' => $rule], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = $this->find($id);
        $rule->update($this->validated($request, false));
        return response()->json(['message' => 'Automation updated.', 'automation' => $rule->fresh()]);
    }

    public function toggle(int $id): JsonResponse
    {
        $rule = $this->find($id);
        $rule->update(['is_active' => !$rule->is_active]);
        return response()->json(['automation' => $rule->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Automation deleted.']);
    }

    /** Dry-run a keyword against a rule without sending anything. */
    public function test(Request $request, int $id): JsonResponse
    {
        $rule = $this->find($id);
        $text = (string) $request->validate(['text' => ['required', 'string']])['text'];
        return response()->json([
            'matches' => $rule->matches($text),
            'covers_media' => $rule->coversMedia($request->input('media_id')),
        ]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'instagram_account_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:instagram_accounts,id'],
            'name'                 => [$req, 'string', 'max:150'],
            'trigger'              => ['sometimes', Rule::in(['comment', 'dm', 'story_reply'])],
            'keywords'             => [$req, 'array', 'min:1'],
            'keywords.*'           => ['string', 'max:60'],
            'match_type'           => ['sometimes', Rule::in(['any', 'all', 'exact'])],
            'media_scope'          => ['sometimes', Rule::in(['all', 'selected'])],
            'media_ids'            => ['sometimes', 'nullable', 'array'],
            'media_ids.*'          => ['string'],
            'dm_message'           => [$req, 'string', 'max:900'],
            'public_reply'         => ['sometimes', 'nullable', 'string', 'max:300'],
            'reply_once_per_user'  => ['sometimes', 'boolean'],
            'handoff_to_ai'        => ['sometimes', 'boolean'],
            'is_active'            => ['sometimes', 'boolean'],
            'priority'             => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);
    }

    private function assertAccount(int $accountId): void
    {
        InstagramAccount::where('id', $accountId)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }

    private function find(int $id): InstagramAutomation
    {
        return InstagramAutomation::whereHas('account', fn ($q) => $q->where('company_id', auth()->user()->company_id))
            ->findOrFail($id);
    }
}
