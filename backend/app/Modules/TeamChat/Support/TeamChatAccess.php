<?php

namespace App\Modules\TeamChat\Support;

use App\Models\User;
use App\Support\Entitlements;

/** Who may use team chat: the company's plan / add-on must include the feature AND the role must hold the permission. */
class TeamChatAccess
{
    public static function featureOn(User $u): bool
    {
        return $u->isSuperAdmin() || ($u->company && Entitlements::has($u->company, 'team_chat'));
    }

    public static function canUse(User $u): bool       { return $u->is_active && self::featureOn($u) && $u->hasPermission('team_chat.view'); }
    public static function canGroups(User $u): bool    { return self::canUse($u) && $u->hasPermission('team_chat.groups'); }
    public static function canModerate(User $u): bool  { return self::canUse($u) && ($u->isOwner() || $u->hasPermission('team_chat.moderate')); }
}
