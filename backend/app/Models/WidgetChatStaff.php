<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-staff live-chat preferences (currently just "mute new-chat alerts"). */
class WidgetChatStaff extends Model
{
    protected $table = 'widget_chat_staff';
    protected $guarded = [];
    protected $casts = ['muted' => 'boolean'];
}
