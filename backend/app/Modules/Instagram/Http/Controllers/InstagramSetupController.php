<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Auth\InstagramAuthProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** "Is this connection really ready?" — powers the setup checklist shown after connecting an account or Page. */
class InstagramSetupController extends Controller
{
    private const REQUIRED_SCOPES = [
        'instagram' => ['instagram_business_basic', 'instagram_business_manage_messages', 'instagram_business_manage_comments'],
        'facebook'  => [],
    ];

    public function __construct(private readonly InstagramAuthProvider $auth) {}

    public function status(int $id): JsonResponse
    {
        $a = $this->find($id);
        [$tokenValid, $subscribed, $error] = $this->probe($a);

        $channel = $a->channel ?? 'instagram';
        $granted = array_values($a->scopes ?? []);
        $required = self::REQUIRED_SCOPES[$channel] ?? [];
        $events = DB::table('instagram_webhook_events')->where('instagram_account_id', $a->id)->where('created_at', '>=', now()->subDays(7));

        $wantedFields = array_map('trim', explode(',', $channel === 'facebook'
            ? config('services.messenger.subscribed_fields') : config('services.instagram.subscribed_fields')));
        $missing = $subscribed === null ? [] : array_values(array_diff($wantedFields, $subscribed));

        return response()->json([
            'account' => $a->only(['id', 'channel', 'username', 'name', 'profile_picture_url', 'followers_count', 'status', 'auth_type']),
            'token_valid' => $tokenValid,
            'token_expires_at' => $a->token_expires_at,
            'auto_refresh' => $a->auth_type === 'instagram_login',
            'permissions' => ['required' => $required, 'granted' => $granted, 'missing' => array_values(array_diff($required, $granted))],
            'webhook' => ['subscribed' => $subscribed === null ? (bool) $a->webhook_subscribed : $missing === [] && $subscribed !== [], 'missing_fields' => $missing],
            'webhook_events_7d' => (clone $events)->count(),
            'last_event_at' => (clone $events)->max('created_at'),
            'error' => $error,
        ]);
    }

    /** Re-subscribe the app to this account's webhooks (the usual fix when the checklist shows it missing). */
    public function resubscribe(int $id): JsonResponse
    {
        $a = $this->find($id);
        $ok = false;
        try {
            if ($a->channel === 'facebook') {
                $ok = Http::withToken($a->access_token)->timeout(20)
                    ->post("https://graph.facebook.com/" . config('services.meta_ads.graph_version', 'v21.0') . "/{$a->ig_user_id}/subscribed_apps",
                        ['subscribed_fields' => config('services.messenger.subscribed_fields')])->successful();
            } elseif ($a->auth_type === 'instagram_login') {
                $ok = $this->auth->subscribe($a);
            }
        } catch (\Throwable) {}
        $a->update(['webhook_subscribed' => $ok]);
        return $this->status($id);
    }

    /** @return array{0:bool,1:?array,2:?string} token valid, subscribed field names (null = unknown), error text */
    private function probe(InstagramAccount $a): array
    {
        if (!$a->access_token) {
            return [false, [], 'The connection token was removed. Reconnect the account.'];
        }
        try {
            if ($a->channel === 'facebook') {
                $base = 'https://graph.facebook.com/' . config('services.meta_ads.graph_version', 'v21.0');
                $me  = Http::withToken($a->access_token)->timeout(20)->get("{$base}/{$a->ig_user_id}", ['fields' => 'id,name']);
                $sub = Http::withToken($a->access_token)->timeout(20)->get("{$base}/{$a->ig_user_id}/subscribed_apps");
                $fields = $sub->successful() ? collect($sub->json('data', []))->flatMap(fn ($app) => $app['subscribed_fields'] ?? [])->unique()->values()->all() : null;
                return [$me->successful(), $fields, $me->successful() ? null : ($me->json('error.message') ?? 'Token rejected')];
            }
            if ($a->auth_type === 'instagram_login') {
                $v = config('services.instagram.ig_graph_version');
                $me  = Http::withToken($a->access_token)->timeout(20)->get("https://graph.instagram.com/{$v}/me", ['fields' => 'user_id']);
                $sub = Http::withToken($a->access_token)->timeout(20)->get("https://graph.instagram.com/{$v}/me/subscribed_apps");
                $fields = $sub->successful() ? collect($sub->json('data', []))->flatMap(fn ($app) => array_map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f, $app['subscribed_fields'] ?? []))->filter()->unique()->values()->all() : null;
                if (!$me->successful()) {
                    $a->update(['status' => 'token_expired']);
                }
                return [$me->successful(), $fields, $me->successful() ? null : ($me->json('error.message') ?? 'Token rejected')];
            }
        } catch (\Throwable $e) {
            return [false, null, 'Could not reach Meta: ' . $e->getMessage()];
        }
        return [true, null, null];   // token-based Instagram accounts: validated when they were connected
    }

    private function find(int $id): InstagramAccount
    {
        $allowed = auth()->user()->allowedAccountIds('instagram_account');
        return InstagramAccount::where('company_id', auth()->user()->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->findOrFail($id);
    }
}
