<?php

namespace App\Modules\Instagram\Auth;

use App\Models\InstagramAccount;

/**
 * How a workspace connects an Instagram professional account. {@see InstagramLoginProvider} is the
 * "Instagram API with Instagram Login" implementation; a Facebook-Login-for-Business ("Unified login")
 * provider can implement this later without touching automation code.
 */
interface InstagramAuthProvider
{
    public function authorizeUrl(string $state): string;

    /**
     * Trade an OAuth `code` for a long-lived token and the account profile.
     *
     * @return array{ig_user_id:string,username:?string,name:?string,profile_picture_url:?string,followers_count:int,access_token:string,expires_at:?\Carbon\Carbon,scopes:array}
     */
    public function exchangeCode(string $code): array;

    /** Returns [new_token, expires_at]. Throws on failure. */
    public function refresh(InstagramAccount $account): array;

    public function subscribe(InstagramAccount $account): bool;

    public function unsubscribe(InstagramAccount $account): void;
}
