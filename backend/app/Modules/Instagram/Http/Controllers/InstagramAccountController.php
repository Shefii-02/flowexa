<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramClient;
use App\Modules\Instagram\Services\InstagramListingImporter;
use Illuminate\Http\{JsonResponse, Request};

class InstagramAccountController extends Controller
{
    public function __construct(
        private readonly InstagramClient $client,
        private readonly InstagramListingImporter $importer,
    ) {}

    /**
     * "Connect via Facebook" account picker: given a short-lived User access token (from the Meta
     * Graph API Explorer, or a Facebook Login flow), list the Pages it can manage — each already
     * carrying its own Page access token — plus the linked Instagram Business Account, if any, so
     * the company can pick a page instead of hand-typing IDs.
     */
    public function discoverPages(Request $request): JsonResponse
    {
        $token = $request->validate(['user_access_token' => ['required', 'string']])['user_access_token'];
        try {
            $pages = $this->client->listPages($token);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not list pages for that token: ' . $e->getMessage()], 422);
        }
        return response()->json(['pages' => $pages]);
    }

    public function index(): JsonResponse
    {
        $accounts = InstagramAccount::where('company_id', auth()->user()->company_id)
            ->withCount(['automations', 'conversations'])
            ->get();
        return response()->json(['accounts' => $accounts]);
    }

    /**
     * Connect an Instagram business account. Expects the IG Business Account id and a Page access
     * token that has instagram_manage_messages + instagram_manage_comments + pages_messaging.
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'ig_user_id'   => ['required', 'string', 'max:40'],
            'access_token' => ['required', 'string'],
            'page_id'      => ['nullable', 'string', 'max:40'],
            'page_name'    => ['nullable', 'string', 'max:200'],
        ]);

        $probe = new InstagramAccount(['ig_user_id' => $d['ig_user_id'], 'access_token' => $d['access_token']]);
        try {
            $info = $this->client->accountInfo($probe);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not verify the account/token: ' . $e->getMessage()], 422);
        }
        if (empty($info['id'])) {
            return response()->json(['message' => 'That IG user id / token did not resolve to an account.'], 422);
        }

        $account = InstagramAccount::updateOrCreate(
            ['ig_user_id' => $d['ig_user_id']],
            [
                'company_id'          => auth()->user()->company_id,
                'connected_by'        => auth()->id(),
                'username'            => $info['username'] ?? null,
                'name'                => $info['name'] ?? null,
                'page_id'             => $d['page_id'] ?? null,
                'page_name'           => $d['page_name'] ?? null,
                'access_token'        => $d['access_token'],
                'profile_picture_url' => $info['profile_picture_url'] ?? null,
                'followers_count'     => $info['followers_count'] ?? 0,
                'is_active'           => true,
                'last_synced_at'      => now(),
            ]
        );

        return response()->json(['message' => "Connected @{$account->username}.", 'account' => $account], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->find($id);
        $d = $request->validate([
            'ai_enabled'            => ['sometimes', 'boolean'],
            'ai_persona'            => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ai_language'           => ['sometimes', 'string', 'max:20'],
            'mirror_customer_style' => ['sometimes', 'boolean'],
            'is_active'             => ['sometimes', 'boolean'],
            'access_token'          => ['sometimes', 'string'],
            'page_id'               => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        $account->update($d);
        return response()->json(['account' => $account->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Instagram account disconnected.']);
    }

    /** Refresh username/followers + recent media (for the automation media picker). */
    public function sync(int $id): JsonResponse
    {
        $account = $this->find($id);
        try {
            $info = $this->client->accountInfo($account);
            $account->update([
                'username'            => $info['username'] ?? $account->username,
                'name'                => $info['name'] ?? $account->name,
                'profile_picture_url' => $info['profile_picture_url'] ?? $account->profile_picture_url,
                'followers_count'     => $info['followers_count'] ?? $account->followers_count,
                'last_synced_at'      => now(),
            ]);
            $media = $this->client->media($account, 50);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['account' => $account->fresh(), 'media' => $media]);
    }

    public function media(int $id): JsonResponse
    {
        $account = $this->find($id);
        try {
            return response()->json(['media' => $this->client->media($account, 60)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Pull the account's posts/reels in as draft catalog listings (photo + caption included). */
    public function importListings(Request $request, int $id): JsonResponse
    {
        $account = $this->find($id);
        $ids = $request->validate([
            'media_ids'   => ['nullable', 'array'],
            'media_ids.*' => ['string'],
        ])['media_ids'] ?? null;

        try {
            $result = $this->importer->import($account, $ids);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'  => "{$result['imported']} imported, {$result['updated']} refreshed. Review them under Listings & Products.",
            'imported' => $result['imported'],
            'updated'  => $result['updated'],
            'listings' => $result['listings'],
        ]);
    }

    private function find(int $id): InstagramAccount
    {
        return InstagramAccount::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
