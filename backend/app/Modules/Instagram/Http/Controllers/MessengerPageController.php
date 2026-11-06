<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramClient;
use App\Modules\MetaAds\Services\MetaGraphClient;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Connect / disconnect Facebook Pages for Messenger automation (stored as channel = 'facebook' accounts). */
class MessengerPageController extends Controller
{
    public function __construct(private readonly InstagramClient $client, private readonly MetaGraphClient $graph) {}

    /** Facebook Login user token → the Pages that person manages, each with its own Page token. */
    public function discover(Request $request): JsonResponse
    {
        $token = $request->validate(['user_access_token' => ['required', 'string']])['user_access_token'];
        $token = $this->longLived($token);
        try {
            $pages = $this->graph->getAllPages('/me/accounts', $token, ['fields' => 'id,name,access_token,fan_count,picture{url},tasks'], 100);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not list your Pages: ' . $e->getMessage()], 422);
        }
        $connected = InstagramAccount::where('channel', 'facebook')->where('company_id', auth()->user()->company_id)->pluck('ig_user_id')->all();
        return response()->json(['pages' => collect($pages)->map(fn ($p) => [
            'page_id' => $p['id'], 'name' => $p['name'] ?? null, 'page_token' => $p['access_token'] ?? null,
            'fans' => $p['fan_count'] ?? null, 'picture' => $p['picture']['data']['url'] ?? null,
            'connected' => in_array($p['id'], $connected, true),
        ])->all()]);
    }

    public function connect(Request $request): JsonResponse
    {
        $d = $request->validate(['page_id' => ['required', 'string', 'max:40'], 'page_token' => ['required', 'string']]);
        $companyId = auth()->user()->company_id;

        $existing = InstagramAccount::withTrashed()->where('ig_user_id', $d['page_id'])->first();
        if ($existing && $existing->company_id !== $companyId) {
            if ($existing->trashed() || !$existing->is_active) { $existing->forceDelete(); $existing = null; }
            else return response()->json(['message' => 'That Page is already connected to another workspace. Disconnect it there first.'], 409);
        }
        if (!$existing || $existing->trashed()) {
            $limit = auth()->user()->company?->plan?->max_instagram_accounts;
            if ($limit !== null && InstagramAccount::where('company_id', $companyId)->count() >= $limit) {
                return response()->json(['message' => "Your plan allows {$limit} connected account(s). Upgrade your plan to connect more.",
                    'error_code' => 'plan_limit_reached', 'resource' => 'instagram_accounts'], 403);
            }
        }

        $probe = new InstagramAccount(['channel' => 'facebook', 'ig_user_id' => $d['page_id'], 'access_token' => $d['page_token']]);
        try {
            $info = $this->client->accountInfo($probe);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not verify that Page/token: ' . $e->getMessage()], 422);
        }

        $account = $existing ?? new InstagramAccount(['ig_user_id' => $d['page_id']]);
        if ($account->trashed()) $account->restore();
        $account->fill([
            'channel' => 'facebook', 'company_id' => $companyId, 'connected_by' => auth()->id(), 'auth_type' => 'page',
            'username' => $info['name'] ?? null, 'name' => $info['name'] ?? null, 'page_id' => $d['page_id'], 'page_name' => $info['name'] ?? null,
            'profile_picture_url' => $info['picture']['data']['url'] ?? null, 'followers_count' => $info['fan_count'] ?? 0,
            'access_token' => $d['page_token'], 'token_expires_at' => null, 'is_active' => true, 'status' => 'active', 'last_synced_at' => now(),
        ])->save();

        $account->update(['webhook_subscribed' => $this->subscribe($account)]);
        return response()->json(['message' => "Connected {$account->name}.", 'account' => $account->fresh()], 201);
    }

    public function disconnect(int $id): JsonResponse
    {
        $account = InstagramAccount::where('channel', 'facebook')->where('company_id', auth()->user()->company_id)->findOrFail($id);
        try { Http::withToken($account->access_token)->timeout(20)->delete($this->graphUrl("/{$account->ig_user_id}/subscribed_apps")); } catch (\Throwable) {}
        $account->automations()->update(['is_active' => false]);
        \App\Models\InstagramFlow::where('instagram_account_id', $account->id)->update(['is_active' => false]);
        $account->update(['access_token' => '', 'is_active' => false, 'status' => 'disconnected', 'webhook_subscribed' => false]);
        return response()->json(['message' => 'Disconnected.']);
    }

    private function subscribe(InstagramAccount $a): bool
    {
        try {
            return Http::withToken($a->access_token)->timeout(20)
                ->post($this->graphUrl("/{$a->ig_user_id}/subscribed_apps"), ['subscribed_fields' => config('services.messenger.subscribed_fields')])->successful();
        } catch (\Throwable $e) {
            Log::warning('Messenger page subscribe failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Short-lived user token → long-lived (Page tokens minted from it do not expire). Falls back to the given token. */
    private function longLived(string $token): string
    {
        $id = config('services.messenger.app_id'); $secret = config('services.messenger.app_secret');
        if (!$id || !$secret) return $token;
        try {
            $r = Http::timeout(20)->get($this->graphUrl('/oauth/access_token'), [
                'grant_type' => 'fb_exchange_token', 'client_id' => $id, 'client_secret' => $secret, 'fb_exchange_token' => $token,
            ]);
            return $r->successful() ? ($r->json('access_token') ?: $token) : $token;
        } catch (\Throwable) {
            return $token;
        }
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . config('services.meta_ads.graph_version', 'v21.0') . '/' . ltrim($path, '/');
    }
}
