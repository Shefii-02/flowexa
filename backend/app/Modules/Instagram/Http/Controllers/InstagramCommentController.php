<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramClient;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Comment moderation: read a post's comments, reply to one, hide it from the public thread, or
 * delete it outright. All four require the instagram_manage_comments permission on the Page
 * token (see InstagramClient::REQUIRED_PERMISSIONS).
 */
class InstagramCommentController extends Controller
{
    public function __construct(private readonly InstagramClient $client) {}

    /** Comments (with nested replies) on one post/reel. */
    public function index(int $accountId, string $mediaId): JsonResponse
    {
        $account = $this->find($accountId);
        try {
            return response()->json(['comments' => $this->client->mediaComments($account, $mediaId)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reply(Request $request, int $accountId, string $commentId): JsonResponse
    {
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:900']])['text'];
        $account = $this->find($accountId);
        try {
            $this->client->replyToComment($account, $commentId, $text);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Reply posted.']);
    }

    public function hide(Request $request, int $accountId, string $commentId): JsonResponse
    {
        $hide = (bool) $request->boolean('hide', true);
        $account = $this->find($accountId);
        try {
            $this->client->hideComment($account, $commentId, $hide);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => $hide ? 'Comment hidden.' : 'Comment unhidden.']);
    }

    public function destroy(int $accountId, string $commentId): JsonResponse
    {
        $account = $this->find($accountId);
        try {
            $this->client->deleteComment($account, $commentId);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Comment deleted.']);
    }

    /** Account-level analytics (instagram_manage_insights). */
    public function insights(int $accountId): JsonResponse
    {
        $account = $this->find($accountId);
        try {
            return response()->json(['insights' => $this->client->accountInsights($account)]);
        } catch (\App\Modules\MetaAds\Services\MetaApiException $e) {
            // Meta's "Application does not have permission for this action" etc. — not an error the user can act on
            // from a raw message. Explain it; the page shows a clear notice instead of a red failure.
            if ($e->isAuthError() || in_array($e->metaCode, [10, 200, 3], true) || str_contains(mb_strtolower($e->getMessage()), 'permission')) {
                return response()->json(['insights' => null, 'unavailable' => true, 'reason' => 'permission',
                    'message' => 'Insights are not enabled for this connection. Your Meta app needs the "instagram_business_manage_insights" permission (and the account must allow it). Reconnect the account after it is enabled.']);
            }
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function find(int $id): InstagramAccount
    {
        return InstagramAccount::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
