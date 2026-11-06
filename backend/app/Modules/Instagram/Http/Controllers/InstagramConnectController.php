<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Auth\InstagramAuthProvider;
use App\Modules\Instagram\Auth\SignedRequest;
use App\Modules\Instagram\Jobs\DeleteInstagramUserData;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Crypt, DB, Log};
use Illuminate\Support\Str;

class InstagramConnectController extends Controller
{
    public function __construct(private readonly InstagramAuthProvider $auth) {}

    public function connectUrl(): JsonResponse
    {
        $state = Crypt::encryptString(json_encode([
            'c' => auth()->user()->company_id,
            'u' => auth()->id(),
            'n' => Str::random(16),
            'e' => now()->addMinutes(15)->timestamp,
        ]));
        return response()->json(['url' => $this->auth->authorizeUrl($state)]);
    }

    public function callback(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string'], 'state' => ['required', 'string']]);

        $state = $this->readState($d['state']);
        if (!$state || $state['c'] !== auth()->user()->company_id || $state['u'] !== auth()->id()) {
            return response()->json(['message' => 'This connection link expired or is invalid. Please try again.'], 422);
        }

        try {
            $p = $this->auth->exchangeCode($d['code']);
        } catch (\Throwable $e) {
            Log::warning('Instagram connect failed: ' . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $companyId = auth()->user()->company_id;
        $existing  = InstagramAccount::withTrashed()->where('ig_user_id', $p['ig_user_id'])->first();
        if ($existing && $existing->company_id !== $companyId) {
            return response()->json(['message' => 'That Instagram account is already connected to another workspace.'], 409);
        }
        if (!$existing || $existing->trashed()) {
            $limit = auth()->user()->company?->plan?->max_instagram_accounts;
            if ($limit !== null && InstagramAccount::where('company_id', $companyId)->count() >= $limit) {
                return response()->json([
                    'message' => "Your plan allows {$limit} Instagram account(s). Upgrade your plan to connect more.",
                    'error_code' => 'plan_limit_reached', 'resource' => 'instagram_accounts',
                ], 403);
            }
        }

        $account = $existing ?? new InstagramAccount(['ig_user_id' => $p['ig_user_id']]);
        if ($account->trashed()) $account->restore();
        $account->fill([
            'company_id'          => $companyId,
            'connected_by'        => auth()->id(),
            'auth_type'           => 'instagram_login',
            'username'            => $p['username'],
            'name'                => $p['name'],
            'profile_picture_url' => $p['profile_picture_url'],
            'followers_count'     => $p['followers_count'],
            'access_token'        => $p['access_token'],
            'token_expires_at'    => $p['expires_at'],
            'token_refreshed_at'  => now(),
            'scopes'              => $p['scopes'],
            'is_active'           => true,
            'status'              => 'active',
            'last_synced_at'      => now(),
        ])->save();

        $account->update(['webhook_subscribed' => $this->auth->subscribe($account)]);

        return response()->json(['message' => "Connected @{$account->username}.", 'account' => $account], 201);
    }

    /** Disconnect: unsubscribe the app, wipe the token, pause automations. */
    public function disconnect(int $id): JsonResponse
    {
        $account = InstagramAccount::where('company_id', auth()->user()->company_id)->findOrFail($id);
        if ($account->auth_type === 'instagram_login' && $account->access_token) {
            try { $this->auth->unsubscribe($account); } catch (\Throwable) {}
        }
        $this->wipe($account);
        return response()->json(['message' => 'Disconnected.']);
    }

    /** Meta "deauthorize" callback — the user removed the app from their Instagram settings. */
    public function deauthorize(Request $request): JsonResponse
    {
        $data = SignedRequest::parse($request->input('signed_request'), config('services.instagram.app_secret'));
        if (!$data) return response()->json(['message' => 'invalid signed_request'], 400);

        InstagramAccount::where('ig_user_id', (string) ($data['user_id'] ?? ''))->get()->each(fn ($a) => $this->wipe($a));
        return response()->json(['ok' => true]);
    }

    /** Meta "data deletion" callback — must answer with a status URL + confirmation code. */
    public function dataDeletion(Request $request): JsonResponse
    {
        $data = SignedRequest::parse($request->input('signed_request'), config('services.instagram.app_secret'));
        if (!$data || empty($data['user_id'])) return response()->json(['message' => 'invalid signed_request'], 400);

        $code = Str::upper(Str::random(12));
        DB::table('instagram_data_deletions')->insert([
            'code' => $code, 'ig_user_id' => (string) $data['user_id'], 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DeleteInstagramUserData::dispatch($code, (string) $data['user_id']);

        return response()->json([
            'url'               => rtrim(config('app.frontend_url', 'https://teamzo.io'), '/') . '/instagram/deletion-status?code=' . $code,
            'confirmation_code' => $code,
        ]);
    }

    /** Public status lookup for the deletion-status page. */
    public function deletionStatus(Request $request): JsonResponse
    {
        $row = DB::table('instagram_data_deletions')->where('code', (string) $request->query('code'))->first(['status', 'created_at', 'completed_at']);
        return $row ? response()->json($row) : response()->json(['message' => 'Unknown code'], 404);
    }

    private function wipe(InstagramAccount $a): void
    {
        $a->automations()->update(['is_active' => false]);
        \App\Models\InstagramFlow::where('instagram_account_id', $a->id)->update(['is_active' => false]);
        $a->update(['access_token' => '', 'is_active' => false, 'status' => 'disconnected', 'webhook_subscribed' => false]);
    }

    private function readState(string $state): ?array
    {
        try {
            $s = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return null;
        }
        return is_array($s) && ($s['e'] ?? 0) >= now()->timestamp ? $s : null;
    }
}
