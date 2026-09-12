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
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function find(int $id): InstagramAccount
    {
        return InstagramAccount::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
