<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'widget_messages';
    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (WidgetMessage $m) => $m->created_at ??= now());
        static::created(function (WidgetMessage $m) {
            $companyId = WidgetConversation::whereKey($m->widget_conversation_id)->value('company_id');
            \App\Modules\Catalog\Support\WidgetRealtime::changed($companyId, (int) $m->widget_conversation_id, 'message', !in_array($m->role, ['user', 'visitor'], true));
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WidgetConversation::class, 'widget_conversation_id');
    }
}
