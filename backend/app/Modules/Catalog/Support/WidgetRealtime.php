<?php

namespace App\Modules\Catalog\Support;

use App\Services\StaffRealtime;

/** Website-chat change notifications: staff inbox (company room) and, when relevant, the visitor's widget. */
class WidgetRealtime
{
    public static function changed(?int $companyId, int $conversationId, string $kind = 'update', bool $notifyVisitor = false): void
    {
        StaffRealtime::toCompany($companyId, 'widget_chat_update', ['conversation_id' => $conversationId, 'kind' => $kind], "w:{$conversationId}");
        if ($notifyVisitor) StaffRealtime::toWidgetVisitor($conversationId);
    }
}
